# FormWriter reads from descriptors

**Parent spec:** [`logic_code_refactor.md`](logic_code_refactor.md) — this spec covers Step 6.

**Status:** Not started. Steps 1–5 of the parent spec are done; this can begin once a critical mass of action-shaped logic files have descriptors (Step 3 covered 18 files). Independent of Step 7 (REST API + AI consumption of descriptors) — either can ship first.

## Problem

Logic files declare their input shape via `_logic_descriptor()` (added in Step 3). Forms in views still hand-declare every field — name, label, type, required flag, validation hint — duplicating what's already in the descriptor. The duplication means a field added to the descriptor doesn't appear in the form unless someone also edits the view, and the form's validation rules can drift from the logic file's expectations.

After Step 6: descriptors are the single declaration. The form, the client-side validation attributes, the server-side type coercion, the API documentation, and the AI tool schema all read from one place.

## Goal

```php
// Current: developer declares each field
$fw = $page->getFormWriter('register');
$fw->addText('usr_email', 'Email');
$fw->addPassword('usr_password', 'Password');

// After Step 6:
$fw = $page->getFormWriter('register');
$fw->fromDescriptor(register_logic_descriptor());
// All fields generated from the descriptor's 'input' array
```

A developer adds a field to the descriptor and it appears in the form, in the API parameter docs, and in the AI tool schema — without touching the view.

## Scope

`fromDescriptor()` is added to both FormWriter implementations:

- `FormWriterV2HTML5` (vanilla theme default)
- `FormWriterBootstrap` / `FormWriterV2Bootstrap` (admin + Bootstrap themes)

The method takes a descriptor array (the return value of `*_logic_descriptor()`), iterates over the `input` key, and emits one form field per entry using the type → field mapping below.

## Type mapping

Each descriptor `type` maps to a FormWriter field type:

| Descriptor type | FormWriter field |
|----------------|-----------------|
| `string` | `addText` |
| `email` | `addEmail` |
| `password` | `addPassword` |
| `int` | `addNumber` |
| `bool` | `addCheckbox` |
| `select` | `addSelect` (with `options` from descriptor) |
| `text` | `addTextarea` |
| `date` | `addDate` |

The mapping lives in a single private method on each FormWriter class — adding a new descriptor type only requires updating this mapping plus adding any custom field-type to the FormWriter.

## Field-level extras

Descriptor fields can carry additional FormWriter hints beyond what the API/AI surfaces care about:

```php
'email' => [
    'type'        => 'email',
    'required'    => true,
    'label'       => 'Email address',
    'placeholder' => 'you@example.com',
    'help'        => 'We will send a confirmation to this address.',
],
```

`placeholder` and `help` are FormWriter-only hints; the API/AI consumers ignore them. `label` is shared. `required` drives both the HTML5 attribute and server-side validation in apiv1.php (Step 7).

## Fields that don't fit

Some fields can't be expressed as descriptor types: file uploads, rich-text editors, custom widgets, fields with conditional visibility, etc. The pattern:

```php
$fw = $page->getFormWriter('product');
$fw->fromDescriptor(product_logic_descriptor());
$fw->addFileUpload('photo', 'Product photo');  // hand-added after
```

`fromDescriptor()` only emits fields it understands; it doesn't reject the descriptor if it sees unknown types. Hand-added fields and descriptor-driven fields coexist without ordering constraints — the developer arranges them in the order they want by interleaving the calls.

## What this enables

- One declaration drives form HTML, client-side validation attributes, and server-side validation.
- Adding a field to a descriptor automatically appears in every form that uses `fromDescriptor()`.
- Combined with Step 7, API parameter docs and AI tool schemas update from the same edit.
- Removes a class of bug where the form accepts a field the logic doesn't validate (or vice versa).

## Implementation order

1. Add `fromDescriptor(array $descriptor)` to `FormWriterV2HTML5`. Iterate `$descriptor['input']`, dispatch on `type`. Skip unknown types silently.
2. Add the same method to `FormWriterBootstrap` and `FormWriterV2Bootstrap`. Identical iteration, different rendering.
3. Pick one or two existing forms (probably `register` and `login` — both have complete descriptors and simple field lists) and convert their views to call `fromDescriptor()`. Verify visual parity.
4. Document `fromDescriptor()` in `docs/formwriter.md` and reference it from `docs/logic_architecture.md`.
5. Convert remaining action-shaped form views opportunistically — not blocking, can happen file-by-file as descriptors evolve.

## Cost

- One-time FormWriter method addition (~30 lines per FormWriter implementation).
- Per-form conversion is a 1–3 line view edit; net code reduction in the view.
- Risk: a descriptor change silently changes form rendering. Mitigation: descriptors are typed and reviewed; the form-generation method is a pure transform.

## What this step does NOT include

- Retiring `_logic_api()` — that's Step 7.
- Server-side validation against the descriptor schema — also Step 7 (apiv1.php boundary).
- Touching descriptor coverage — descriptors are added file-by-file as need arises; this step doesn't require new descriptors.

## Dependencies

- Step 3 (descriptors exist on action-shaped files) — done.
- Step 4 (signatures normalized) — done. Not strictly required for FormWriter changes, but means form-handling logic files have the consistent `(array $input)` shape that pairs cleanly with descriptor-driven forms.
- Step 5 (single calling convention) — done. Same rationale.

Step 6 doesn't depend on Step 7 and vice versa. Either order works.
