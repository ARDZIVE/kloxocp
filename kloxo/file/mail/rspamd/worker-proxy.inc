# Kloxo CP (GENERATED). Exim uses the rspamd protocol on 11333 (worker-normal).
# The milter proxy is left off; do not enable it unless an MTA talks milter.
enabled = false;
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
bind_socket = "127.0.0.1:11332";
