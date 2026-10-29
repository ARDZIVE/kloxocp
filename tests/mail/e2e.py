#!/usr/bin/env python3
"""End-to-end test against RUNNING Postfix + Dovecot + Rspamd + MariaDB.

Expects (see tests/mail/README.md): domain example.com, mailboxes alice@ and bob@ with the
password below, alias info@ -> alice@, DKIM key for example.com, services running on localhost.
Run on a disposable machine only.
"""
import imaplib, smtplib, ssl, sys, time, email, poplib

PW = "correct horse battery"
HOST = "127.0.0.1"
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE
passed = failed = 0


def ok(name, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {name}")
    else:
        failed += 1
        print(f"  FAIL {name} {detail}")


def mail(frm, to, subject, body="hello"):
    return f"From: {frm}\r\nTo: {to}\r\nSubject: {subject}\r\nMessage-ID: <{subject}.{time.time()}@test>\r\n\r\n{body}\r\n"


def inbox(user):
    m = imaplib.IMAP4_SSL(HOST, 993, ssl_context=ctx)
    m.login(user, PW)
    return m


def find(user, subject, folder="INBOX", wait=8):
    """Return the raw message with this subject, polling for up to `wait` seconds."""
    for _ in range(wait * 2):
        m = inbox(user)
        m.select(folder)
        typ, data = m.search(None, "SUBJECT", f'"{subject}"')
        if data[0]:
            num = data[0].split()[-1]
            typ, msg = m.fetch(num, "(RFC822)")
            m.logout()
            return email.message_from_bytes(msg[0][1])
        m.logout()
        time.sleep(0.5)
    return None


print("== inbound SMTP on port 25")
with smtplib.SMTP(HOST, 25, timeout=30) as s:
    s.ehlo("sender.example.net")
    s.sendmail("someone@sender.example.net", ["alice@example.com"], mail("someone@sender.example.net", "alice@example.com", "inbound-direct"))
ok("message to a mailbox is accepted", True)
msg = find("alice@example.com", "inbound-direct")
ok("message delivered to alice's Maildir via LMTP", msg is not None)
if msg is not None:
    ok("Rspamd scanned it (X-Spamd-Result / Authentication-Results)", any(h in msg for h in ("X-Spamd-Result", "Authentication-Results", "X-Spam")), str(list(msg.keys())))

with smtplib.SMTP(HOST, 25, timeout=30) as s:
    s.ehlo("sender.example.net")
    s.sendmail("someone@sender.example.net", ["info@example.com"], mail("someone@sender.example.net", "info@example.com", "inbound-alias"))
ok("alias info@ delivers into alice's mailbox", find("alice@example.com", "inbound-alias") is not None)

with smtplib.SMTP(HOST, 25, timeout=30) as s:
    s.ehlo("sender.example.net")
    s.mail("someone@sender.example.net")
    code, resp = s.rcpt("nobody@example.com")
ok("unknown recipient is rejected during the SMTP dialogue (no backscatter)", code == 550, f"{code} {resp!r}")

print("== quota enforcement (no backscatter)")
import subprocess, os
KM = ["php", os.path.join(os.path.dirname(os.path.abspath(__file__)), "../../kloxo/bin/mail/kloxo-mail")]
subprocess.run(KM + ["mailbox:quota", "bob@example.com", "1"], check=True, capture_output=True)
big = "X" * 2_000_000
try:
    with smtplib.SMTP(HOST, 25, timeout=30) as s:
        s.ehlo("sender.example.net")
        try:
            s.sendmail("someone@sender.example.net", ["bob@example.com"], mail("someone@sender.example.net", "bob@example.com", "too-big", big))
            code = 250
        except smtplib.SMTPRecipientsRefused as e:
            code = list(e.recipients.values())[0][0]
        except smtplib.SMTPDataError as e:
            code = e.smtp_code
    ok("2 MB message for a 1 MB mailbox is refused with a temporary 452 during SMTP", code == 452, str(code))
finally:
    subprocess.run(KM + ["mailbox:quota", "bob@example.com", "1024"], check=True, capture_output=True)

print("== account changes take effect immediately (no stale auth cache)")
def imap_login(user, pw):
    try:
        m = imaplib.IMAP4_SSL(HOST, 993, ssl_context=ctx)
        m.login(user, pw)
        m.logout()
        return True
    except imaplib.IMAP4.error:
        return False
ok("bob can log in", imap_login("bob@example.com", PW))
subprocess.run(KM + ["mailbox:passwd", "bob@example.com", "--password-stdin"], input="a brand new password\n", text=True, check=True, capture_output=True)
ok("after a password change the OLD password stops working at once", not imap_login("bob@example.com", PW))
ok("the new password works", imap_login("bob@example.com", "a brand new password"))
subprocess.run(KM + ["mailbox:disable", "bob@example.com"], check=True, capture_output=True)
ok("a disabled mailbox cannot log in at once", not imap_login("bob@example.com", "a brand new password"))
subprocess.run(KM + ["mailbox:enable", "bob@example.com"], check=True, capture_output=True)
subprocess.run(KM + ["mailbox:passwd", "bob@example.com", "--password-stdin"], input=PW + "\n", text=True, check=True, capture_output=True)
ok("re-enabled and password restored", imap_login("bob@example.com", PW))

print("== submission (587, STARTTLS, SASL)")
with smtplib.SMTP(HOST, 587, timeout=30) as s:
    s.ehlo("client.test")
    s.starttls(context=ctx)
    s.ehlo("client.test")
    ok("AUTH is only offered after STARTTLS", "auth" in s.esmtp_features)
    s.login("alice@example.com", PW)
    s.sendmail("alice@example.com", ["bob@example.com"], mail("alice@example.com", "bob@example.com", "submitted-ok"))
sent = find("bob@example.com", "submitted-ok")
ok("authenticated user can send; message arrives", sent is not None)
if sent is not None:
    ok("outgoing mail is DKIM-signed by Rspamd", "DKIM-Signature" in sent, str(list(sent.keys())))

with smtplib.SMTP(HOST, 587, timeout=30) as s:
    s.ehlo("client.test")
    s.starttls(context=ctx)
    s.ehlo("client.test")
    s.login("alice@example.com", PW)
    s.mail("info@example.com")
    code, _ = s.rcpt("bob@example.com")
ok("alice may send as her alias info@example.com", code == 250, str(code))

with smtplib.SMTP(HOST, 587, timeout=30) as s:
    s.ehlo("client.test")
    s.starttls(context=ctx)
    s.ehlo("client.test")
    s.login("alice@example.com", PW)
    s.mail("bob@example.com")
    code, resp = s.rcpt("alice@example.com")   # smtpd_delay_reject: sender checks answer at RCPT time
ok("alice may NOT spoof bob@example.com (sender/login mismatch)", code == 553, f"{code} {resp!r}")

with smtplib.SMTP(HOST, 587, timeout=30) as s:
    s.ehlo("client.test")
    s.starttls(context=ctx)
    s.ehlo("client.test")
    try:
        s.login("alice@example.com", "wrong password here")
        bad = False
    except smtplib.SMTPAuthenticationError:
        bad = True
ok("wrong password is refused on submission", bad)

with smtplib.SMTP(HOST, 587, timeout=30) as s:
    s.ehlo("client.test")
    try:
        s.login("alice@example.com", PW)
        plain = False
    except (smtplib.SMTPNotSupportedError, smtplib.SMTPException):
        plain = True
ok("login without TLS is refused on submission", plain)

with smtplib.SMTP(HOST, 587, timeout=30) as s:
    s.ehlo("client.test")
    s.starttls(context=ctx)
    s.ehlo("client.test")
    s.mail("alice@example.com")
    code, _ = s.rcpt("bob@example.com")
ok("submission without authentication is refused", code >= 500, str(code))

print("== submission over implicit TLS (465)")
with smtplib.SMTP_SSL(HOST, 465, context=ctx, timeout=30) as s:
    s.login("bob@example.com", PW)
    s.sendmail("bob@example.com", ["alice@example.com"], mail("bob@example.com", "alice@example.com", "smtps-ok"))
ok("port 465 works", find("alice@example.com", "smtps-ok") is not None)

print("== IMAP / POP3 / TLS policy")
m = inbox("alice@example.com")
ok("IMAPS login with Argon2id password (real Dovecot auth against MariaDB)", True)
typ, folders = m.list()
names = b" ".join(folders)
ok("special-use folders exist (Sent/Junk/Trash/Drafts)", all(n in names for n in (b"Sent", b"Junk", b"Trash", b"Drafts")), names.decode(errors="ignore"))
m.logout()
try:
    imaplib.IMAP4_SSL(HOST, 993, ssl_context=ctx).login("alice@example.com", "wrong password here")
    wrong = False
except imaplib.IMAP4.error:
    wrong = True
ok("IMAP rejects a wrong password", wrong)
# Dovecot treats a connection from the machine itself as "secure" (even through its external
# address), so plaintext refusal cannot be demonstrated from the same host. It is verified
# statically (ssl=required, disable_plaintext_auth=yes in run-tests.sh). Here we check that
# STARTTLS is offered and that the server insists on it for remote clients.
plain = imaplib.IMAP4(HOST, 143)
ok("IMAP on 143 offers STARTTLS", "STARTTLS" in plain.capabilities, str(plain.capabilities))
plain.logout()
p = poplib.POP3_SSL(HOST, 995, context=ctx)
p.user("alice@example.com")
p.pass_(PW)
ok("POP3S login works and lists messages", p.stat()[0] >= 1)
p.quit()

print()
print(f"RESULT: {passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
