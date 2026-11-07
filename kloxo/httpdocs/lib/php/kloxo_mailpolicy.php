<?php
declare(strict_types=1);

/**
 * SMTP protection (the replacement for Spamdyke): one policy, rendered for the
 * MTA chosen in Switch Program (Exim or Postfix).
 *
 * Spamdyke setting          Exim                                  Postfix
 * ------------------------  ------------------------------------  ----------------------------------------
 * greeting delay            connect ACL `delay` + smtp_enforce_   postscreen_greet_wait (enforce)
 *                           sync (early talkers are dropped)
 * max recipients            RCPT ACL on $recipients_count         smtpd_recipient_limit
 * graylisting               RCPT ACL -> Postgrey (policy socket)  check_policy_service -> Postgrey
 * reject empty rDNS         no PTR record (dnsdb)                 reject_unknown_reverse_client_hostname
 * reject unresolvable rDNS  rDNS name not forward-confirmed       reject_unknown_client_hostname
 * reject IP in CC rDNS      IP inside a ccTLD rDNS name           regexp table on the rDNS name
 * reject missing sender MX  verify = sender                       reject_unknown_sender_domain
 * DNS RBL servers           dnslists                              reject_rbl_client
 * blacklist headers         DATA ACL on $h_<name>:                header_checks (regexp table)
 * whitelist IPs             hostlist exempting every rule         check_client_access / postscreen access list
 *
 * Pure functions: validation, rendering and planning. Nothing here runs a
 * program; `kloxo-mail policy:apply` installs the result (render, validate with
 * the MTA's own checker, install, roll back on failure).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoMailPolicy
{
    public const FILE = '/etc/kloxo/mail-policy.json';
    public const POSTGREY_HOST = '127.0.0.1';
    public const POSTGREY_PORT = 10023;
    public const POSTFIX_DIR = '/etc/postfix/kloxo';

    /** Mail Server Settings sub-actions that change the policy. */
    public const SUBACTIONS = ['update', 'spamdyke', 'add_mail_graylist_wlist_a', 'delete_mail_graylist_wlist_a'];

    private const DNS_NAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** Settings used when nothing was saved yet (Kloxo-MR values); the policy is off. */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'greet_delay' => 1,
            'max_rcpnts' => 150,
            'graylist' => true,
            'graylist_min_secs' => 300,
            'graylist_max_secs' => 1814400,
            'reject_empty_rdns' => false,
            'reject_ip_in_cc_rdns' => false,
            'reject_missing_sender_mx' => true,
            'reject_unresolvable_rdns' => false,
            'dns_blacklists' => [],
            'blacklist_headers' => [],
            'whitelist_ips' => [],
        ];
    }

    // ------------------------------------------------------------------
    // Input: panel fields or JSON -> validated policy
    // ------------------------------------------------------------------

    private static function flag(mixed $v, bool $default): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower(trim((string) $v)), ['on', '1', 'yes', 'true'], true);
    }

    private static function int(array $raw, string $key, string $label, int $min, int $max, int $default): int
    {
        $v = $raw[$key] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return $default;
        }
        if (is_int($v)) {
            $s = (string) $v;
        } else {
            $s = is_string($v) ? trim($v) : '';
        }
        if (preg_match('/^\d{1,9}$/', $s) !== 1 || (int) $s < $min || (int) $s > $max) {
            throw new InvalidArgumentException("{$label} must be a whole number from {$min} to {$max}");
        }

        return (int) $s;
    }

    /** @return list<string> */
    public static function parseRbl(mixed $v): array
    {
        $items = is_array($v) ? $v : preg_split('/[\s,]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ((array) $items as $z) {
            $z = strtolower(trim((string) $z, " \t\r\n."));
            if ($z === '') {
                continue;
            }
            if (preg_match(self::DNS_NAME, $z) !== 1) {
                throw new InvalidArgumentException("DNS RBL server \"{$z}\" is not a valid DNS zone name");
            }
            $out[$z] = $z;
        }
        if (count($out) > 20) {
            throw new InvalidArgumentException('At most 20 DNS RBL servers are allowed');
        }

        return array_values($out);
    }

    /**
     * One rule per line: "Header-Name: text". `*` in the text matches any run of
     * characters; the text may match anywhere in the header, ignoring case.
     * Empty lines and lines starting with # are ignored.
     *
     * @return list<array{header:string,pattern:string}>
     */
    public static function parseHeaders(mixed $v): array
    {
        $lines = is_array($v) ? $v : preg_split('/\R/', (string) $v);
        $out = [];
        $n = 0;
        foreach ((array) $lines as $line) {
            if (is_array($line)) {
                $line = ($line['header'] ?? '') . ': ' . ($line['pattern'] ?? '');
            }
            $line = trim((string) $line);
            $n++;
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, ':');
            $name = $pos === false ? '' : trim(substr($line, 0, $pos));
            $text = $pos === false ? '' : trim(substr($line, $pos + 1));
            if (preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $name) !== 1) {
                throw new InvalidArgumentException("Blacklist Headers line {$n}: use \"Header-Name: text\"");
            }
            if ($text === '' || strlen($text) > 200 || preg_match('/^[\x20-\x5b\x5d-\x7e]+$/', $text) !== 1) {
                throw new InvalidArgumentException(
                    "Blacklist Headers line {$n}: the text must be 1-200 printable ASCII characters without a backslash"
                );
            }
            $out[strtolower($name) . "\0" . $text] = ['header' => $name, 'pattern' => $text];
        }
        if (count($out) > 100) {
            throw new InvalidArgumentException('At most 100 blacklist header rules are allowed');
        }

        return array_values($out);
    }

    /** @return list<string> */
    public static function parseWhitelist(mixed $v): array
    {
        $items = is_array($v) ? $v : preg_split('/[\s,]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ((array) $items as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '') {
                continue;
            }
            $addr = $ip;
            $bits = null;
            if (str_contains($ip, '/')) {
                [$addr, $b] = explode('/', $ip, 2);
                if (preg_match('/^\d{1,3}$/', $b) !== 1) {
                    throw new InvalidArgumentException("Whitelist IP \"{$ip}\" is not valid");
                }
                $bits = (int) $b;
            }
            $v4 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $v6 = !$v4 && filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (!$v4 && !$v6) {
                throw new InvalidArgumentException("Whitelist IP \"{$ip}\" is not a valid IPv4/IPv6 address or CIDR");
            }
            if ($bits !== null && $bits > ($v4 ? 32 : 128)) {
                throw new InvalidArgumentException("Whitelist IP \"{$ip}\" has an invalid prefix length");
            }
            $norm = strtolower($addr) . ($bits !== null ? '/' . $bits : '');
            $out[$norm] = $norm;
        }
        if (count($out) > 500) {
            throw new InvalidArgumentException('At most 500 whitelist entries are allowed');
        }

        return array_values($out);
    }

    /**
     * Validate and canonicalise a policy. $raw may come from the panel form
     * ("on"/"off" flags, text areas) or from the JSON file.
     *
     * @throws InvalidArgumentException with a message fit for the admin
     */
    public static function normalize(array $raw): array
    {
        $d = self::defaults();
        $min = self::int($raw, 'graylist_min_secs', 'Graylist entry minimum lifetime', 1, 604800, $d['graylist_min_secs']);
        $max = self::int($raw, 'graylist_max_secs', 'Graylist entry maximum lifetime', 1, 31536000, $d['graylist_max_secs']);
        if ($max < $min) {
            throw new InvalidArgumentException('Graylist entry maximum lifetime must not be smaller than the minimum lifetime');
        }

        return [
            'enabled' => self::flag($raw['enabled'] ?? null, $d['enabled']),
            'greet_delay' => self::int($raw, 'greet_delay', 'Greet Delay', 0, 30, $d['greet_delay']),
            'max_rcpnts' => self::int($raw, 'max_rcpnts', 'Maximum Recipients', 0, 10000, $d['max_rcpnts']),
            'graylist' => self::flag($raw['graylist'] ?? null, $d['graylist']),
            'graylist_min_secs' => $min,
            'graylist_max_secs' => $max,
            'reject_empty_rdns' => self::flag($raw['reject_empty_rdns'] ?? null, $d['reject_empty_rdns']),
            'reject_ip_in_cc_rdns' => self::flag($raw['reject_ip_in_cc_rdns'] ?? null, $d['reject_ip_in_cc_rdns']),
            'reject_missing_sender_mx' => self::flag($raw['reject_missing_sender_mx'] ?? null, $d['reject_missing_sender_mx']),
            'reject_unresolvable_rdns' => self::flag($raw['reject_unresolvable_rdns'] ?? null, $d['reject_unresolvable_rdns']),
            'dns_blacklists' => self::parseRbl($raw['dns_blacklists'] ?? []),
            'blacklist_headers' => self::parseHeaders($raw['blacklist_headers'] ?? []),
            'whitelist_ips' => self::parseWhitelist($raw['whitelist_ips'] ?? []),
        ];
    }

    public static function load(string $path = self::FILE): array
    {
        if (!is_file($path)) {
            return self::defaults();
        }
        $json = json_decode((string) file_get_contents($path), true, 16);
        if (!is_array($json)) {
            throw new InvalidArgumentException("{$path} is not valid JSON");
        }

        return self::normalize($json);
    }

    /** Default RBL / header lists shipped with the panel (one place to fix a dead zone). */
    public static function defaultFile(string $name, ?string $root = null): string
    {
        $root ??= dirname(__DIR__, 3);
        $f = rtrim($root, '/') . '/file/mail/policy/' . $name;

        return is_file($f) ? trim((string) file_get_contents($f)) : '';
    }

    /**
     * Raw policy from the Mail Server Settings object (the fields the form saves).
     *
     * @param array<int,string>|null $whitelist
     */
    public static function fromServerMail(object $sm, ?array $whitelist = null): array
    {
        $on = static fn (string $k): string => (isset($sm->$k) && is_string($sm->$k)) ? $sm->$k : '';
        if ($whitelist === null) {
            $whitelist = [];
            if (isset($sm->mail_graylist_wlist_a) && (is_array($sm->mail_graylist_wlist_a) || $sm->mail_graylist_wlist_a instanceof Traversable)) {
                foreach ($sm->mail_graylist_wlist_a as $k => $o) {
                    $whitelist[] = is_object($o) && isset($o->nname) ? (string) $o->nname : (string) $k;
                }
            } elseif (isset($sm->coma_mail_graylist_wlist_a) && is_string($sm->coma_mail_graylist_wlist_a)) {
                $whitelist = array_filter(explode(',', $sm->coma_mail_graylist_wlist_a), 'strlen');
            }
        }

        return [
            'enabled' => $on('spamdyke_flag'),
            'greet_delay' => $on('greet_delay'),
            'max_rcpnts' => $on('max_rcpnts'),
            'graylist' => $on('graylist_flag'),
            'graylist_min_secs' => $on('graylist_min_secs'),
            'graylist_max_secs' => $on('graylist_max_secs'),
            'reject_empty_rdns' => $on('reject_empty_rdns_flag'),
            'reject_ip_in_cc_rdns' => $on('reject_ip_in_cc_rdns_flag'),
            'reject_missing_sender_mx' => $on('reject_missing_sender_mx_flag'),
            'reject_unresolvable_rdns' => $on('reject_unresolvable_rdns_flag'),
            'dns_blacklists' => $on('dns_blacklists'),
            'blacklist_headers' => $on('blacklist_headers'),
            'whitelist_ips' => $whitelist,
        ];
    }

    // ------------------------------------------------------------------
    // Shared pieces
    // ------------------------------------------------------------------

    /** Regex for a header rule text: literal parts joined by `.*`, every other character escaped. */
    public static function ruleRegex(string $pattern): string
    {
        $parts = [];
        foreach (explode('*', $pattern) as $part) {
            $q = '';
            foreach (str_split($part) as $ch) {
                $q .= (ctype_alnum($ch) || $ch === ' ' || $ch === '_') ? $ch : '\\' . $ch;
            }
            $parts[] = $q;
        }

        return implode('.*', $parts);
    }

    /** True when the policy needs the Postgrey daemon. */
    public static function needsPostgrey(array $p): bool
    {
        return $p['enabled'] && $p['graylist'];
    }

    /** `--max-age` is in days: round the maximum lifetime up. */
    public static function postgreyMaxAgeDays(array $p): int
    {
        return max(1, (int) ceil($p['graylist_max_secs'] / 86400));
    }

    /** Content of /etc/default/postgrey (read by the Debian/Ubuntu unit). */
    public static function postgreyDefaults(array $p): string
    {
        return "# Generated by Kloxo CP (kloxo-mail policy:apply). Do not edit by hand.\n"
            . 'POSTGREY_OPTS="--inet=' . self::POSTGREY_HOST . ':' . self::POSTGREY_PORT
            . ' --delay=' . $p['graylist_min_secs']
            . ' --max-age=' . self::postgreyMaxAgeDays($p) . "\"\n"
            . "POSTGREY_TEXT=\"Greylisted, please try again later\"\n";
    }

    // ------------------------------------------------------------------
    // Exim
    // ------------------------------------------------------------------

    /**
     * Text for the @@POLICY_*@@ placeholders of file/mail/exim/exim4.conf.
     * With the policy off the result is the original behaviour (sender verification only).
     *
     * @return array<string,string>
     */
    public static function eximSnippets(array $p): array
    {
        $on = $p['enabled'];
        $wl = $p['whitelist_ips'] === [] ? ['127.0.0.1'] : $p['whitelist_ips'];
        $skip = "          !authenticated = *\n"
            . "          !hosts = +kloxo_local_hosts : +kloxo_policy_whitelist\n";

        $s = [
            'POLICY_MAIN' => "# SMTP protection (generated from the Kloxo policy; whitelist IPs skip every rule)\n"
                . 'hostlist kloxo_policy_whitelist = <; ' . implode(' ; ', $wl),
            'POLICY_CONNECT' => '',
            'POLICY_MAIL' => '',
            'POLICY_SENDER_VERIFY' => '',
            'POLICY_RCPT' => '',
            'POLICY_DATA' => '',
        ];

        if ($on && $p['greet_delay'] > 0) {
            $s['POLICY_CONNECT'] = "  accept  hosts = +kloxo_local_hosts : +kloxo_policy_whitelist\n"
                . "  accept  condition = \${if or{{eq{\$received_port}{587}}{eq{\$received_port}{465}}}{yes}{no}}\n"
                . "  # smtp_enforce_sync (default) drops clients that talk before the banner\n"
                . "  warn    delay = {$p['greet_delay']}s\n";
        }

        // sender domain must resolve (the original behaviour; a policy may switch it off)
        if (!$on || $p['reject_missing_sender_mx']) {
            $s['POLICY_SENDER_VERIFY'] = "  deny    message = invalid sender\n"
                . "          !hosts = +kloxo_local_hosts" . ($on ? ' : +kloxo_policy_whitelist' : '') . "\n"
                . "          !authenticated = *\n"
                . "          !verify = sender\n";
        }

        $mail = '';
        if ($on && $p['reject_empty_rdns']) {
            $mail .= "  deny    message = Reverse DNS (PTR) record required\n" . $skip
                . "          condition = \${lookup dnsdb{ptr=\$sender_host_address}{no}{yes}}\n";
        }
        if ($on && $p['reject_unresolvable_rdns']) {
            $mail .= "  deny    message = Reverse DNS name of \$sender_host_address does not resolve back to it\n" . $skip
                . "          condition = \${if eq{\$sender_host_name}{}{\${if bool{\$host_lookup_deferred}{no}{yes}}}{no}}\n";
        }
        if ($on && $p['reject_ip_in_cc_rdns']) {
            $ip = '${sg{$sender_host_address}{\N\.\N}{[.-]}}';
            $mail .= "  deny    message = Reverse DNS name contains the client IP address\n" . $skip
                . '          condition = ${if match{$sender_host_address}{\N^\d{1,3}(\.\d{1,3}){3}$\N}'
                . '{${if match{$sender_host_name}{\N(?i)\.[a-z]{2}$\N}'
                . '{${if match{$sender_host_name}{\N(^|[^0-9])\N' . $ip . '\N([^0-9]|$)\N}{yes}{no}}}{no}}}{no}}' . "\n";
        }
        if ($on && $p['dns_blacklists'] !== []) {
            $mail .= "  # DNS lists: Spamhaus answers 127.255.255.x to resolvers it blocks; that is not a listing\n"
                . "  deny    message = \$sender_host_address is listed at \$dnslist_domain\n" . $skip
                . '          dnslists = ' . implode(' : ', $p['dns_blacklists']) . "\n"
                . '          condition = ${if match{$dnslist_value}{\N^127\.255\.255\.\N}{no}{yes}}' . "\n";
        }
        $s['POLICY_MAIL'] = $mail;

        $rcpt = '';
        if ($on && $p['max_rcpnts'] > 0) {
            $rcpt .= "  defer   message = 452 4.5.3 Too many recipients\n"
                . "          !hosts = +kloxo_policy_whitelist\n"
                . "          condition = \${if >={\$recipients_count}{{$p['max_rcpnts']}}{yes}{no}}\n";
        }
        if (self::needsPostgrey($p)) {
            $clean = static fn (string $v): string => '${sg{' . $v . '}{\N[\r\n]\N}{_}}';
            $req = 'request=smtpd_access_policy${nl}protocol_state=RCPT${nl}protocol_name=ESMTP${nl}'
                . 'client_address=$sender_host_address${nl}'
                . 'client_name=' . $clean('${if def:sender_host_name{$sender_host_name}{unknown}}') . '${nl}'
                . 'helo_name=' . $clean('$sender_helo_name') . '${nl}'
                . 'sender=' . $clean('$sender_address') . '${nl}'
                . 'recipient=' . $clean('$local_part@$domain') . '${nl}${nl}';
            $guard = "          domains = +kloxo_mail_domains\n          !hosts = +kloxo_policy_whitelist\n";
            // conditions first: a modifier (set) runs as soon as it is reached
            $rcpt .= "  # Graylisting: Postgrey answers on " . self::POSTGREY_HOST . ':' . self::POSTGREY_PORT . "\n"
                . "  warn    domains = +kloxo_mail_domains\n          !hosts = +kloxo_policy_whitelist\n"
                . "          set acl_m_grey = \${readsocket{inet:" . self::POSTGREY_HOST . ':' . self::POSTGREY_PORT
                . '}{' . $req . "}{20s}{\${nl}}{TEMPFAIL}}\n"
                . "  defer   message = Graylist service unavailable, try again later\n" . $guard
                . "          condition = \${if eq{\$acl_m_grey}{TEMPFAIL}{yes}{no}}\n"
                . "  defer   message = Greylisted, please try again later\n" . $guard
                . '          condition = ${if match{$acl_m_grey}{\N(?i)^action=defer\N}{yes}{no}}' . "\n";
        }
        if ($rcpt !== '') {
            // unknown users are refused before any delay is applied (no backscatter, no pointless graylist entry)
            $rcpt = "  deny    domains = +kloxo_mail_domains\n          message = Unknown user\n          !verify = recipient\n" . $rcpt;
        }
        $s['POLICY_RCPT'] = $rcpt;

        $data = '';
        if ($on) {
            foreach ($p['blacklist_headers'] as $r) {
                $data .= "  deny    message = Message rejected by header policy\n" . $skip
                    . '          condition = ${if match{$h_' . strtolower($r['header']) . ':}{\N(?i)'
                    . self::ruleRegex($r['pattern']) . '\N}{yes}{no}}' . "\n";
            }
        }
        $s['POLICY_DATA'] = $data;

        return $s;
    }

    // ------------------------------------------------------------------
    // Postfix
    // ------------------------------------------------------------------

    /** main.cf parameters this policy owns (anything not planned is returned to its original value). */
    public const POSTFIX_PARAMS = [
        'smtpd_delay_reject', 'smtpd_client_restrictions', 'smtpd_sender_restrictions',
        'smtpd_recipient_restrictions', 'smtpd_recipient_limit', 'header_checks',
        'postscreen_greet_wait', 'postscreen_greet_action', 'postscreen_access_list',
    ];

    /**
     * What to do to Postfix: main.cf parameter values (null = back to the original),
     * map files below POSTFIX_DIR, and the postscreen front end for the greeting delay.
     *
     * @return array{params:array<string,?string>,files:array<string,string>,postscreen:bool,postgrey:bool}
     */
    public static function postfixPlan(array $p, string $dir = self::POSTFIX_DIR): array
    {
        $params = array_fill_keys(self::POSTFIX_PARAMS, null);
        $files = [
            'whitelist.cidr' => '',
            'postscreen_access.cidr' => '',
            'header_checks.regexp' => '',
            'rdns_ip_cc.regexp' => '',
        ];
        $plan = ['params' => $params, 'files' => $files, 'postscreen' => false, 'postgrey' => false];
        if (!$p['enabled']) {
            return $plan;
        }

        $hdr = "# Generated by Kloxo CP (kloxo-mail policy:apply). Do not edit by hand.\n";
        foreach ($files as $k => $_) {
            $plan['files'][$k] = $hdr;
        }
        foreach ($p['whitelist_ips'] as $ip) {
            $plan['files']['whitelist.cidr'] .= "{$ip} OK\n";
            $plan['files']['postscreen_access.cidr'] .= "{$ip} permit\n";
        }
        foreach ($p['blacklist_headers'] as $r) {
            $plan['files']['header_checks.regexp'] .= '/^' . $r['header'] . ':.*' . self::ruleRegex($r['pattern'])
                . "/i REJECT Message rejected by header policy\n";
        }

        $white = 'check_client_access cidr:' . $dir . '/whitelist.cidr';
        $client = ['permit_mynetworks', 'permit_sasl_authenticated', $white];
        if ($p['reject_empty_rdns']) {
            $client[] = 'reject_unknown_reverse_client_hostname';
        }
        if ($p['reject_unresolvable_rdns']) {
            $client[] = 'reject_unknown_client_hostname';
        }
        if ($p['reject_ip_in_cc_rdns']) {
            $plan['files']['rdns_ip_cc.regexp'] .= '/(^|[^0-9])[0-9]{1,3}[-.][0-9]{1,3}[-.][0-9]{1,3}[-.][0-9]{1,3}([^0-9].*)?\.[a-z][a-z]$/i'
                . " REJECT Reverse DNS name contains an IP address\n";
            $client[] = 'check_reverse_client_hostname_access regexp:' . $dir . '/rdns_ip_cc.regexp';
        }
        foreach ($p['dns_blacklists'] as $z) {
            // 127.255.255.x is how Spamhaus refuses blocked resolvers; it is not a listing
            $client[] = 'reject_rbl_client ' . $z . '=127.0.0.[2..255]';
        }
        if (count($client) > 3) {
            $params['smtpd_client_restrictions'] = implode(', ', $client);
            $params['smtpd_delay_reject'] = 'yes';
        }

        if ($p['reject_missing_sender_mx']) {
            $params['smtpd_sender_restrictions'] = 'permit_mynetworks, permit_sasl_authenticated, reject_unknown_sender_domain';
        }

        if (self::needsPostgrey($p)) {
            // relay control lives in smtpd_relay_restrictions (checked first; kloxo-mail refuses to apply without it)
            $params['smtpd_recipient_restrictions'] = 'permit_mynetworks, permit_sasl_authenticated, ' . $white
                . ', check_policy_service inet:' . self::POSTGREY_HOST . ':' . self::POSTGREY_PORT;
            $plan['postgrey'] = true;
        }
        if ($p['max_rcpnts'] > 0) {
            $params['smtpd_recipient_limit'] = (string) $p['max_rcpnts'];
        }
        if ($p['blacklist_headers'] !== []) {
            $params['header_checks'] = 'regexp:' . $dir . '/header_checks.regexp';
        }
        if ($p['greet_delay'] > 0) {
            $params['postscreen_greet_wait'] = $p['greet_delay'] . 's';
            $params['postscreen_greet_action'] = 'enforce';
            $params['postscreen_access_list'] = 'permit_mynetworks, cidr:' . $dir . '/postscreen_access.cidr';
            $plan['postscreen'] = true;
        }
        $plan['params'] = $params;

        return $plan;
    }

    // ------------------------------------------------------------------
    // Panel side: hand the policy to the root tool
    // ------------------------------------------------------------------

    public static function rootDir(): string
    {
        global $sgbl;

        if (isset($sgbl) && is_object($sgbl) && isset($sgbl->__path_program_root)
            && is_string($sgbl->__path_program_root) && $sgbl->__path_program_root !== '') {
            return rtrim($sgbl->__path_program_root, '/');
        }
        $env = getenv('KLOXO_ROOT');

        return rtrim(is_string($env) && $env !== '' ? $env : '/opt/kloxo', '/');
    }

    /**
     * Save the policy and install it for the MTA ('exim' or 'postfix'; 'none' only saves).
     * Runs as root (kloxo-backend). Postgrey is installed when graylisting needs it.
     *
     * @return array{ok:bool,out:string,error:string}
     */
    public static function apply(array $raw, string $mta): array
    {
        if (!in_array($mta, ['exim', 'postfix', 'none'], true)) {
            return ['ok' => false, 'out' => '', 'error' => 'invalid_smtp_driver'];
        }
        try {
            $p = self::normalize($raw);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'out' => '', 'error' => $e->getMessage()];
        }
        $file = self::policyFile();
        $old = is_file($file) ? (string) file_get_contents($file) : null;
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'out' => '', 'error' => "cannot create {$dir}"];
        }
        $tmp = $file . '.tmp' . getmypid();
        file_put_contents($tmp, json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        chmod($tmp, 0644);
        rename($tmp, $file);

        $res = self::runTool($mta, $p);
        if (!$res['ok']) {
            // keep the file and the live configuration in step
            if ($old === null) {
                @unlink($file);
            } else {
                file_put_contents($file, $old);
            }
        }

        return $res;
    }

    /** Driver entry point (root): apply the Mail Server Settings object, throw with the reason on failure. */
    public static function applyOrThrow(object $main, string $mta): void
    {
        $res = self::apply(self::fromServerMail($main), $mta);
        if (!$res['ok']) {
            $msg = 'SMTP protection could not be applied: ' . $res['error'];
            throw class_exists('lxException') ? new lxException($msg, '', '') : new RuntimeException($msg);
        }
    }

    /** Install the saved policy for another MTA (Switch Program). No saved policy: nothing to do. */
    public static function reapply(string $mta): array
    {
        $file = self::policyFile();
        if (!is_file($file)) {
            return ['ok' => true, 'out' => 'no saved SMTP protection settings', 'error' => ''];
        }
        try {
            $p = self::load($file);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'out' => '', 'error' => $e->getMessage()];
        }

        return self::runTool($mta, $p);
    }

    public static function policyFile(): string
    {
        $env = getenv('KLOXO_MAIL_POLICY');

        return is_string($env) && $env !== '' ? $env : self::FILE;
    }

    /** @return array{ok:bool,out:string,error:string} */
    private static function runTool(string $mta, array $p): array
    {
        if ($mta === 'none') {
            return ['ok' => true, 'out' => 'SMTP is none: settings saved only', 'error' => ''];
        }
        if (self::needsPostgrey($p) && !is_executable('/usr/sbin/postgrey') && class_exists('KloxoApt')) {
            $r = KloxoApt::install(['postgrey']);
            if (empty($r['ok'])) {
                return ['ok' => false, 'out' => '', 'error' => 'Could not install postgrey: ' . (string) ($r['error'] ?? 'apt failed')];
            }
        }
        $tool = self::rootDir() . '/bin/mail/kloxo-mail';
        if (!is_file($tool)) {
            return ['ok' => false, 'out' => '', 'error' => 'kloxo-mail is missing'];
        }
        $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        $cmd = [$php, $tool, 'policy:apply', '--mta=' . $mta, '--policy=' . self::policyFile()];
        $proc = @proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return ['ok' => false, 'out' => '', 'error' => 'could not start kloxo-mail'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $err = trim((string) preg_replace('/^error:\s*/', '', trim($err)));

        return [
            'ok' => $rc === 0,
            'out' => trim($out),
            'error' => $rc === 0 ? '' : ($err !== '' ? substr($err, 0, 600) : 'kloxo-mail policy:apply failed'),
        ];
    }
}
