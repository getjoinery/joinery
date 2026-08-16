#!/usr/bin/env python3
"""
mail_archive_inventory.py - an independent inventory of an mbox.

WHY THIS IS PYTHON. The point of this file is that it is NOT the importer. The
platform finds message boundaries with MboxSplitter and parses MIME with Horde;
if the inventory used either, a bug in them would be invisible to the very
report meant to catch it. Python's stdlib `mailbox` and `email` are a wholly
separate implementation of the same two jobs, already installed, and answerable
to nobody in this codebase. A disagreement between the two is a finding.

Read-only. It never writes to the archive and never touches the database.

Output is JSONL, one object per message:

    {"index": 0,
     "message_id": "<abc@example.com>",   # "" when the message carries none
     "envelope_offset": 0,                # byte offset of the "From " separator
     "body_offset": 62,                   # first byte after it - what the
                                          #   platform's locators are keyed on
     "length": 4211,
     "labels": ["Inbox", "Receipts"],
     "attachments": [{"filename": "receipt.pdf", "size": 20481}]}

`body_offset` exists because the two splitters mean different things by "where
the message starts": Python's table of contents points at the "From " line,
while the platform's `offset:length` locator points just past it. Recording
both lets the reconciliation match messages that have no Message-ID by
position, which is the only handle those messages have.

Usage:
    mail_archive_inventory.py ARCHIVE.mbox [-o OUT.jsonl] [--progress N]

See specs/mail_import_loss_proof.md.

@version 1.0
"""

import argparse
import json
import mailbox
import sys


def body_offset(fh, envelope_start):
    """The first byte after the 'From ' separator line, or the start itself.

    The platform's mbox locator points at content, not at the separator, so this
    is the offset the two sides can actually compare.
    """
    if envelope_start < 0:
        return -1
    fh.seek(envelope_start)
    line = fh.readline()
    if not line.startswith(b'From '):
        # Not a separator: the archive is a fragment, or the table of contents
        # disagrees with the bytes. Report the position unchanged and let the
        # reconciliation notice.
        return envelope_start
    return envelope_start + len(line)


def attachments_of(message):
    """Non-text parts, counted the way the platform counts them.

    The first inline text/plain and text/html with no filename are the message
    body; everything else that is not a multipart container is an attachment.
    Mirrors the classification in ImapIngestor/InboundEmailRouter closely enough
    for a COUNT comparison, which is all the reconciliation makes of it — the
    two sides name and de-duplicate parts differently, so comparing filenames
    would report mismatches that are not mismatches.
    """
    found = []
    seen_plain = False
    seen_html = False
    for part in message.walk():
        if part.get_content_maintype() == 'multipart':
            continue
        ctype = (part.get_content_type() or '').lower()
        filename = part.get_filename()
        disposition = (part.get_content_disposition() or '').lower()
        inline_text = (ctype in ('text/plain', 'text/html')
                       and disposition != 'attachment'
                       and not filename)
        if inline_text and ctype == 'text/plain' and not seen_plain:
            seen_plain = True
            continue
        if inline_text and ctype == 'text/html' and not seen_html:
            seen_html = True
            continue
        try:
            payload = part.get_payload(decode=True)
            size = len(payload) if payload else 0
        except Exception:
            size = 0
        found.append({'filename': filename or '', 'size': size})
    return found


def labels_of(message):
    """Gmail's X-Gmail-Labels, split. Informational — the reconciliation does
    not key on it, because the platform folds these into folders and pseudo-
    labels by its own rules."""
    raw = message.get('X-Gmail-Labels')
    if not raw:
        return []
    return [item.strip() for item in raw.split(',') if item.strip()]


def main():
    parser = argparse.ArgumentParser(description='Inventory an mbox independently of the platform.')
    parser.add_argument('archive', help='path to the .mbox file')
    parser.add_argument('-o', '--out', help='output JSONL path (default: stdout)')
    parser.add_argument('--progress', type=int, default=0,
                        help='print a progress line to stderr every N messages')
    args = parser.parse_args()

    box = mailbox.mbox(args.archive, create=False)

    # The table of contents IS the boundary decision we came here to get an
    # independent second opinion on, so we want the offsets themselves, not just
    # the parsed messages. It is built lazily; ask for it explicitly.
    toc = getattr(box, '_toc', None)
    if not toc:
        try:
            box._generate_toc()
            toc = getattr(box, '_toc', None) or {}
        except Exception as exc:                       # pragma: no cover
            print('could not read message offsets: %s' % exc, file=sys.stderr)
            toc = {}

    out = open(args.out, 'w', encoding='utf-8') if args.out else sys.stdout
    raw = open(args.archive, 'rb')

    count = 0
    no_id = 0
    try:
        for key in box.iterkeys():
            try:
                message = box[key]
            except Exception as exc:
                # A message this parser cannot read is itself a finding: record
                # the position so the reconciliation can name it, and continue.
                start, stop = toc.get(key, (-1, -1))
                record = {'index': count, 'message_id': '', 'envelope_offset': start,
                          'body_offset': body_offset(raw, start),
                          'length': (stop - start) if stop and start >= 0 else -1,
                          'labels': [], 'attachments': [], 'error': str(exc)}
                out.write(json.dumps(record) + '\n')
                count += 1
                continue

            start, stop = toc.get(key, (-1, -1))
            message_id = (message.get('Message-ID') or message.get('Message-Id') or '').strip()
            if not message_id:
                no_id += 1

            record = {
                'index': count,
                'message_id': message_id,
                'envelope_offset': start,
                'body_offset': body_offset(raw, start),
                'length': (stop - start) if (stop and start is not None and start >= 0) else -1,
                'labels': labels_of(message),
                'attachments': attachments_of(message),
            }
            out.write(json.dumps(record) + '\n')
            count += 1
            if args.progress and count % args.progress == 0:
                print('%d messages' % count, file=sys.stderr)
    finally:
        raw.close()
        if args.out:
            out.close()

    print('inventory complete: %d messages, %d without a Message-ID' % (count, no_id),
          file=sys.stderr)


if __name__ == '__main__':
    main()
