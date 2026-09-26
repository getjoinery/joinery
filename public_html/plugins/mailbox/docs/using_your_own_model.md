# Using your own model

AI summaries and the security scan for your end-to-end encrypted mail run in
your browser, against an AI model you choose. Your mail is sent from your
browser to that model and nowhere else. Joinery never sees it. Whoever runs
the model does, so choose one you trust: a service you hold a key for, or a
model on a computer of your own.

## Setting it up

1. **Choose your model.** Open Email settings and find *Your AI model*. When
   the site runs a model of its own, *Use this site's model* is one click.
   Otherwise, or to use another, choose *Enter a different model* and give
   the address your model's service gives you (for example
   `https://api.fireworks.ai/inference/v1`), its key (empty if it needs
   none) and the model's name. You confirm it is you with your passkey,
   because this decides where your mail may be sent. The start of the
   address, up to the host name, is kept with your account; the rest, the key
   and the model name stay in this browser, so on another computer you enter
   those again in the AI panel on your mail.
2. **Press Test.** Open your mail; in the AI panel, *Your AI, your model*
   shows what you chose. Test sends the security scan's real instructions
   with a made-up sample message to your model and tells you what happened,
   in one line, with the fix when something stopped it.
3. **Turn the AI on for the mailbox.** In the same panel, turn on *Email
   triage* for one-line summaries, *Email security scan* for the danger
   check, or both. On an end-to-end encrypted mailbox each card says *Runs on
   your device while this mailbox is open*.

## What you will see

- A one-line summary under each new message in the list, as your model
  finishes it, while the mailbox is open and your vault unlocked.
- The security scan's verdict and its red flags in the message, marked
  *judged by* the model's name.
- **Summarize** and **Scan now** under an opened message, for one message at
  a time, with the answer shown at once.

The work runs in one browser tab at a time and judges up to 200 messages per
visit, so a large mailbox is done over a few visits. When your model cannot be
reached, the panel says so in one line and the mailbox keeps working without
AI. Nothing errors in the reader.

## The site's own model, with one click

When the site you use runs its own model on hardware on its network, Email
settings offers it first, and the AI panel offers it too: *Use this site's
model*, naming the model and the address. One click sets the address and the
model for you. You still
confirm with your passkey, because that confirmation is what allows the mail
page to send your mail there. The offer says plainly who runs that machine:
if you are not the site's operator, the operator could see mail sent to it,
so choose it only if you trust them with your mail.

## A service you hold a key for

Fireworks, OpenAI and any host that speaks the OpenAI chat format (vLLM, LM
Studio and others) all work. Paste the base address the service gives you for
chat completions, without `/chat/completions` on the end.

Your domain's AI setting may keep mail on your own machines. If it does, the
panel says the address you chose is not allowed for that mailbox; the domain's
administrator can loosen the setting on the domain page.

## A model on a computer of your own

Ollama on the computer you are using works with three settings on your side.

- **Let this site call it.** Ollama answers only sites named in its
  `OLLAMA_ORIGINS` setting. Add your Joinery site's address (for example
  `https://mail.example.com`) to that setting on the computer running Ollama
  and restart Ollama. The address to paste in Email settings is
  `http://localhost:11434/v1`; the key stays empty; the model name is the one
  you pulled, such as `llama3.1:8b`.
- **Allow your browser once.** Chrome asks before a web page may reach a
  program on your computer or your network. Allow it once for your site and
  it remembers.
- **A model that thinks is fine.** The page asks it not to reason unless the
  recipe's thinking level says so, the same way the site's own AI does, so
  its answer is not spent on reasoning.
- **Give the model enough room.** A message and its instructions come to
  about 3,600 tokens. Ollama's chat address takes no context setting from the
  page, so set the model's context on your side, for example with a Modelfile
  line `PARAMETER num_ctx 8192`. A context that is too small fails with a
  message Test shows word for word.

A computer elsewhere on your network works the same way with its address in
place of `localhost`. A plain `http://` address is accepted only for
`localhost` and for private or Tailscale network addresses written as
numbers; a name needs `https://`. `tailscale serve` gives an Ollama an
`https://` address on your Tailscale network, and that site address must be on
Ollama's origins line too. Safari and Firefox may refuse a plain `http://`
call from a secure page; the `https://` route avoids that.

## What Test says, and what to do

- **Reachable**, with the model's name: nothing to do.
- **Your browser asks first** or **your browser blocked it**: allow the site
  to reach your computer in the browser's prompt or site settings.
- **Your model refused this site**: add the site to Ollama's origins line and
  restart Ollama.
- **Wrong key**: check the key with your service.
- **No such model**: check the model's name; on Ollama, pull it first.
- **Context too small**: the model's own message. Raise the model's context.

## Where things are kept

- The host name of your model is kept with your account and named in the mail
  page's own security policy, so the page can send mail to that host and to
  no other. Changing it asks you to confirm it is you again. Removing it never
  asks: it can only narrow where your mail goes.
- The rest of the address, the key and the model name are kept in each browser
  you enter them in. They never reach Joinery.
- The site grades your model by its name. When it grades below what the
  security scan asks for, the panel says so, and the verdicts may be less
  reliable.
