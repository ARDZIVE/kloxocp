# Kloxo CP: global Sieve script, runs before every user's own script.
# Rspamd marks spam with "X-Spam: Yes"; file it into Junk instead of the inbox.
require ["fileinto", "mailbox"];

if header :contains "X-Spam" "Yes" {
    fileinto :create "Junk";
    stop;
}
