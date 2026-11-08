<?php

declare(strict_types=1);

/**
 * Panel config rows (users, domains, DNS, mail, vhosts, settings). Prepared
 * statements only. Deleting a domain drops DNS, mail and vhost rows in one
 * transaction (FK CASCADE plus an explicit delete so the caller sees the count).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoPanelDb
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function open(bool $create = true): self
    {
        return new self(KloxoStore::panel($create));
    }

    public function addUser(string $name, string $passwordHash, string $role = 'client'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, password_hash, email, role, active, created_at)
             VALUES (:name, :hash, :email, :role, 1, :ts)'
        );
        $stmt->execute([
            ':name' => $name,
            ':hash' => $passwordHash,
            ':email' => '',
            ':role' => $role,
            ':ts' => time(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addDomain(int $userId, string $name, string $docRoot): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO domains (user_id, name, document_root, php_version, active, created_at)
             VALUES (:user_id, :name, :root, :php, 1, :ts)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':root' => $docRoot,
            ':php' => '',
            ':ts' => time(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addDns(int $domainId, string $type, string $name, string $content, int $ttl = 3600, ?int $prio = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dns_records (domain_id, type, name, content, ttl, prio)
             VALUES (:domain_id, :type, :name, :content, :ttl, :prio)'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':type' => $type,
            ':name' => $name,
            ':content' => $content,
            ':ttl' => $ttl,
            ':prio' => $prio,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addMail(int $domainId, string $localPart, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_accounts (domain_id, local_part, password_hash, quota_mb, active)
             VALUES (:domain_id, :local_part, :hash, 0, 1)'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':local_part' => $localPart,
            ':hash' => $passwordHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addVhost(int $domainId, string $websiteRoot, string $startFile = 'index.php', bool $ssl = false): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO vhosts (domain_id, website_root, start_file, ssl, extra)
             VALUES (:domain_id, :root, :start, :ssl, :extra)'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':root' => $websiteRoot,
            ':start' => $startFile,
            ':ssl' => $ssl ? 1 : 0,
            ':extra' => '',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings ("key", value) VALUES (:k, :value)
             ON CONFLICT("key") DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([':k' => $key, ':value' => $value]);
    }

    /**
     * @return array{domain:int, dns:int, mail:int, vhosts:int}
     */
    public function deleteDomain(string $name): array
    {
        $find = $this->pdo->prepare('SELECT id FROM domains WHERE name = :name');
        $find->execute([':name' => $name]);
        $id = $find->fetchColumn();
        if ($id === false) {
            return ['domain' => 0, 'dns' => 0, 'mail' => 0, 'vhosts' => 0];
        }
        $domainId = (int) $id;
        $this->pdo->beginTransaction();
        try {
            $dns = $this->countAndDelete('dns_records', $domainId);
            $mail = $this->countAndDelete('mail_accounts', $domainId);
            $vhosts = $this->countAndDelete('vhosts', $domainId);
            $del = $this->pdo->prepare('DELETE FROM domains WHERE id = :id');
            $del->execute([':id' => $domainId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['domain' => 1, 'dns' => $dns, 'mail' => $mail, 'vhosts' => $vhosts];
    }

    /** @return list<array<string,mixed>> */
    public function vhosts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT v.id, d.name AS domain, v.website_root, v.start_file, v.ssl, v.extra
             FROM vhosts v JOIN domains d ON d.id = v.domain_id
             WHERE d.active = 1 ORDER BY d.name'
        );

        return $stmt ? $stmt->fetchAll() : [];
    }

    public function setting(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE "key" = :k');
        $stmt->execute([':k' => $key]);
        $v = $stmt->fetchColumn();

        return $v === false ? $default : (string) $v;
    }

    private function countAndDelete(string $table, int $domainId): int
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            throw new InvalidArgumentException('bad table');
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE domain_id = :id");
        $count->execute([':id' => $domainId]);
        $n = (int) $count->fetchColumn();
        $del = $this->pdo->prepare("DELETE FROM {$table} WHERE domain_id = :id");
        $del->execute([':id' => $domainId]);

        return $n;
    }
}
