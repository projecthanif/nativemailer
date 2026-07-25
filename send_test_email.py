#!/usr/bin/env python3
"""Send a test email with attachment + external link to the local SMTP catcher (127.0.0.1:1025)."""
import smtplib
from email.message import EmailMessage
from pathlib import Path

msg = EmailMessage()
msg["From"] = "Test Sender <test@example.com>"
msg["To"] = "iamustapha213@gmail.com"
msg["Subject"] = "Test email with attachment and external link"
msg.set_content(
    "Hello!\n\n"
    "This is a test email with an attachment sent to the SMTP catcher.\n\n"
    "Check out this external link: https://example.com/external-resource\n"
)

# HTML alternative so the link renders as clickable in the catcher's preview
msg.add_alternative(
    """\
<html>
  <body>
    <p>Hello!</p>
    <p>This is a test email with an attachment sent to the SMTP catcher.</p>
    <p>Check out this
      <a href="https://example.com/external-resource">external link</a>.
    </p>
  </body>
</html>
""",
    subtype="html",
)

logo = Path(__file__).parent / "logo.png"
msg.add_attachment(
    logo.read_bytes(),
    maintype="image",
    subtype="png",
    filename="logo.png",
)

with smtplib.SMTP("127.0.0.1", 1025, timeout=10) as s:
    s.send_message(msg)

print("Sent to 127.0.0.1:1025 with attachment logo.png and an external link")
