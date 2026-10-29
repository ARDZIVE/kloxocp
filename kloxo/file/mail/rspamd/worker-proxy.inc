# Kloxo CP (GENERATED). Postfix talks to this milter endpoint.
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
bind_socket = "127.0.0.1:11332";
