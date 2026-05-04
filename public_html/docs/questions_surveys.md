# Questions & Surveys

The platform has a built-in system for collecting information from users. Before writing custom data-collection code, check whether Questions, Surveys, or Product Requirements cover your use case.

- **Questions** — individual form fields (text, dropdown, radio, checkbox, confirmation)
- **Surveys** — ordered collections of questions presented on a standalone page
- **Product Requirements** — questions/surveys collected at purchase time (see [Product Requirements](product_requirements.md))

## When to use

| Use case | Tool |
|---|---|
| Collect responses on a dedicated page | Survey |
| Attach questions to a product checkout | Product Requirements |
| Reuse the same question in multiple surveys or products | Question (shared) |
| Collect data once per user (e.g. event pre-survey) | Survey via Product Requirement |

## Admin UI

| Page | URL |
|---|---|
| All questions | `/admin/admin_questions` |
| Create / edit question | `/admin/admin_question_edit` |
| All surveys | `/admin/admin_surveys` |
| Create / edit survey | `/admin/admin_survey_edit` |
| View survey responses by user | `/admin/admin_survey_users` |
| View one user's answers | `/admin/admin_survey_user_answers` |
| View all answers to a survey | `/admin/admin_survey_answers` |

## Question types

Defined as constants on the `Question` class:

| Constant | Value | Renders as |
|---|---|---|
| `TYPE_SHORT_TEXT` | 1 | Single-line text input |
| `TYPE_LONG_TEXT` | 2 | Multi-line textarea |
| `TYPE_DROPDOWN` | 3 | Select dropdown (requires options) |
| `TYPE_RADIO` | 4 | Radio button group (requires options) |
| `TYPE_CHECKBOX` | 5 | Single checkbox (requires one option — its value) |
| `TYPE_CHECKBOX_LIST` | 6 | Multi-select checkbox list (requires options) |
| `TYPE_CONFIRMATION` | 7 | Scrollable body text + checkbox agreement |

For `TYPE_DROPDOWN`, `TYPE_RADIO`, `TYPE_CHECKBOX`, and `TYPE_CHECKBOX_LIST`, options are stored as `QuestionOption` records linked to the question.

For `TYPE_CONFIRMATION`, extended config is stored as JSON in `qst_config`:
```json
{
  "body_text": "<p>Full terms here...</p>",
  "checkbox_label": "I agree to the terms above",
  "scrollable": true
}
```

## Data models

```
Question         (qst_questions)         — a single question definition
QuestionOption   (qop_question_options)  — options for dropdown/radio/checkbox types
Survey           (svy_surveys)           — a named collection of questions
SurveyQuestion   (srq_survey_questions)  — join: survey ↔ question (with order)
SurveyAnswer     (sva_survey_answers)    — one row per user per question per survey
```

`SurveyAnswer` is unique on `(sva_svy_survey_id, sva_qst_question_id, sva_usr_user_id)` — resubmitting overwrites rather than duplicates.

## Public survey page

The route `/survey?survey_id={encoded_id}` renders a survey and handles submission. Requires a logged-in session (permission 0). On success it redirects to `/survey_finish`.

`survey_id` is passed as an encoded integer — use `LibraryFunctions::encode($survey->key)` to build the URL.

## Rendering questions programmatically

`Question::output_question($formwriter, $value, $append_text)` renders the correct FormWriter field for the question's type, including client-side validation wiring.

```php
require_once(PathHelper::getIncludePath('data/questions_class.php'));

$question = new Question($question_id, true);
$question->output_question($formwriter, $existing_value);
```

The submitted field name is always `question_{question_id}`.

## Reading answers programmatically

```php
// Get one answer
$answer = SurveyAnswer::get_answer($survey_id, $question_id, $user_id);

// Get human-readable answer text (resolves option labels for dropdowns/radios/etc.)
$readable = $question->get_answer_readable($answer->get('sva_answer'));
```

## MultiQuestion filter keys

```php
$questions = new MultiQuestion([
    'deleted'   => false,   // qst_delete_time IS NULL
    'published' => true,    // qst_is_published = TRUE
    'type'      => Question::TYPE_SHORT_TEXT,
]);
$questions->load();
```

## MultiSurveyQuestion filter keys

```php
$survey_questions = new MultiSurveyQuestion(
    ['survey_id' => $survey_id, 'deleted' => false],
    ['srq_order' => 'ASC']
);
$survey_questions->load();
```
