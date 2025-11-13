# Event Extra Information & Survey System Specification

**Status:** Partially Implemented / Needs Completion
**Created:** 2025-10-15
**Priority:** Medium

## Overview

Two related but distinct features exist for collecting additional information from event registrants:
1. **Extra Info Collection** (`evt_collect_extra_info`) - Partially implemented
2. **Event Surveys** (`evt_svy_survey_id`, `evt_survey_required`) - Infrastructure exists but disabled

---

## 1. Extra Info Collection System

### Current Implementation Status: ~60% Complete

### Database Fields

**Events Table (`evt_events`):**
- `evt_collect_extra_info` (boolean) - Flag to indicate extra info should be collected from registrants

**Event Registrants Table (`evr_event_registrants`):**
- `evr_extra_info_completed` (boolean) - Tracks whether registrant has completed extra info
- `evr_recording_consent` (boolean) - Consent to be recorded (one example of extra info)
- `evr_first_event` (boolean) - Whether this is their first event
- `evr_other_events` (text) - List of other events attended
- `evr_health_notes` (text) - Health/dietary restrictions

### Current Workflow

1. **Event Setup** (admin_event_edit.php):
   - Admin can enable/disable `evt_collect_extra_info` checkbox
   - When enabled, registrants will be prompted for additional information

2. **Registration/Purchase** (cart_charge_logic.php:467-469):
   ```php
   if($event->get('evt_collect_extra_info')){
       $email_fill['more_info_required'] = true;
   }
   ```
   - After purchase, email notification includes flag that more info is needed
   - Email template: `event_reciept_content`

3. **Profile Reminder** (profile.php:28-40, profile_logic.php):
   - Code exists but is **COMMENTED OUT**
   - Would show warning on profile page if extra info not completed
   - Links to `/profile/event_register_finish` page

4. **Display in Admin** (admin_event.php):
   - Shows whether registrant completed extra info
   - Displays collected info (recording consent, first event, other events, health notes)
   - Shows link to complete if not done

### What's Working
✅ Database schema in place
✅ Admin toggle to enable/disable
✅ Email notification after purchase
✅ Data storage for basic extra info fields
✅ Admin view of collected data

### What Needs to Be Finished

❌ **User-facing collection form** (`/profile/event_register_finish`)
   - Form doesn't exist or is incomplete
   - Needs to collect the specific extra info fields
   - Should mark `evr_extra_info_completed = true` when done

❌ **Profile page reminders** (currently commented out)
   - Uncomment and test reminder system
   - Should show prominent alert for incomplete registrations

❌ **Customizable fields**
   - Currently hardcoded fields (health notes, first event, etc.)
   - Should allow admins to define what extra info to collect per event
   - Consider JSON field or separate table for custom questions

❌ **Required vs Optional**
   - No way to mark extra info as required vs optional
   - Should prevent event access if required info not completed?

❌ **Deadline enforcement**
   - No deadline for completing extra info
   - Should it expire with registration? Have separate deadline?

### Recommended Next Steps

1. Create/fix `/profile/event_register_finish` page
   - Form to collect the standard extra info fields
   - Save to event_registrant record
   - Mark as completed
   - Redirect back to event or profile

2. Uncomment and test profile page reminders

3. Add customizable fields system (future enhancement)
   - Table: `evt_extra_info_fields`
   - Fields: field name, field type, required/optional, event_id
   - Store answers in JSON or separate answers table

---

## 2. Event Survey System

### Current Implementation Status: ~80% Infrastructure, 0% Connected

### Database Schema

**Surveys Table (`svy_surveys`):**
- Survey definitions (title, description, etc.)
- Fully implemented with data classes

**Survey Questions Table (`srq_survey_questions`):**
- Questions belonging to surveys
- Fully implemented with data classes

**Survey Answers Table (`sva_survey_answers`):**
- User answers to survey questions
- Fully implemented with data classes

**Events Table (`evt_events`):**
- `evt_svy_survey_id` (int4) - FK to surveys table
- `evt_survey_required` (int2) - Whether survey is required (0 = optional, 1 = required)

### Existing Infrastructure

✅ **Complete survey management system:**
   - `/adm/admin_surveys.php` - List surveys
   - `/adm/admin_survey.php` - View survey details
   - `/adm/admin_survey_edit.php` - Create/edit surveys
   - `/adm/admin_survey_answers.php` - View answers
   - `/adm/admin_survey_users.php` - Users who completed survey
   - `/adm/admin_survey_user_answers.php` - Individual user's answers

✅ **User-facing components:**
   - `/logic/survey_logic.php` - Survey display and submission logic
   - `/views/survey_finish.php` - Survey completion view

✅ **Data classes:**
   - `Survey`, `MultiSurvey`
   - `SurveyQuestion`, `MultiSurveyQuestion`
   - `SurveyAnswer`, `MultiSurveyAnswer`

### Current State: DISABLED

The survey connection to events is **completely commented out** in `admin_event_edit.php` (lines 262-290):

```php
/*
$surveys = new MultiSurvey(array('deleted'=>false));
$surveys->load();
$optionvals = $surveys->get_survey_dropdown_array();
echo $formwriter->dropinput("Event survey", "evt_svy_survey_id", "ctrlHolder",
    $optionvals, $event->get('evt_svy_survey_id'), '', 'No Survey');

$optionvals = array("Required"=>1, "Not Required"=>0);
echo $formwriter->dropinput("Event survey required before registration",
    "evt_survey_required", "ctrlHolder", $optionvals,
    $event->get('evt_survey_required'), '', FALSE);
*/
```

**Reason unknown** - possibly:
- Incomplete implementation
- Performance concerns
- Replaced by `evt_collect_extra_info` system
- Planned for future release

### What Needs to Be Finished

❌ **Uncomment and enable survey fields in event edit form**

❌ **Connect survey to registration flow**
   - When should survey be presented?
     - Option A: After registration, before event access
     - Option B: After event, as feedback
     - Option C: Either (configurable)
   - If required before registration:
     - Block event access until completed
     - Show reminder on profile/event pages

❌ **Survey completion tracking**
   - Add field to `evr_event_registrants`: `evr_survey_completed` (boolean)
   - Link survey answers to event registration
   - Consider: one survey per registrant or allow multiple responses?

❌ **Admin reporting**
   - Show survey completion status in admin event view
   - Link to survey results from event page
   - Export survey data per event

❌ **User experience**
   - Notification when survey is available/required
   - Link from event page to survey
   - Thank you message after completion
   - Optional: anonymous vs identified responses

### Recommended Next Steps

1. **Determine the use case:**
   - Pre-event surveys (skill level, expectations, etc.)
   - Post-event surveys (feedback, satisfaction)
   - Both (configurable per event)

2. **Enable the feature:**
   - Uncomment survey dropdown in admin_event_edit.php
   - Test existing survey system is working

3. **Connect to event flow:**
   - Add `evr_survey_completed` field to event_registrants table
   - Integrate survey link into event registration confirmation email
   - Add survey reminder to profile page (if required)
   - Link survey to event_registrant_id to track completion

4. **Admin integration:**
   - Show survey completion stats on admin_event page
   - Add "View Survey Results" link/button
   - Filter survey answers by event

---

## Relationship Between The Two Systems

### Similarities
- Both collect additional information from registrants
- Both can be required or optional
- Both need completion tracking
- Both need reminders/notifications

### Key Differences

| Feature | Extra Info Collection | Survey System |
|---------|----------------------|---------------|
| **Purpose** | Practical logistics data | Feedback/assessment data |
| **Examples** | Dietary restrictions, emergency contact, experience level | Satisfaction rating, learning outcomes, suggestions |
| **Timing** | Usually collected before/during event | Can be before, during, or after event |
| **Flexibility** | Currently fixed fields | Fully customizable questions |
| **Current State** | Partially working | Complete system but disconnected |
| **Data Storage** | Direct columns in event_registrants | Separate answers table |

### Possible Future Direction

**Option 1: Keep Separate**
- Use Extra Info for pre-event logistics
- Use Surveys for feedback/assessment
- Both can be enabled independently

**Option 2: Merge Systems**
- Deprecate hardcoded extra info fields
- Use survey system for all custom data collection
- Add timing/purpose flags to surveys (pre-event vs post-event)
- More flexible but more complex

**Recommendation:** Keep separate for now
- Extra Info = simple, quick logistics
- Surveys = complex, multi-question forms
- Different use cases justify separate systems

---

## Technical Debt & Cleanup Needed

1. **Extra Info:**
   - Finish `/profile/event_register_finish` page
   - Uncomment profile reminders
   - Add customizable fields (future)
   - Add `evt_extra_info_required` field (currently assumed required if enabled)

2. **Surveys:**
   - Uncomment event survey fields
   - Add `evr_survey_completed` to event_registrants
   - Create link between survey_answers and event_registrants
   - Integrate into registration flow
   - Add admin reporting

3. **Documentation:**
   - User guide for extra info vs surveys
   - Admin guide for when to use each
   - Developer docs for extending either system

---

## Testing Checklist

### Extra Info System
- [ ] Enable `evt_collect_extra_info` on test event
- [ ] Register for event as test user
- [ ] Verify email notification includes "more info needed"
- [ ] Access `/profile/event_register_finish` page
- [ ] Complete extra info form
- [ ] Verify `evr_extra_info_completed` set to true
- [ ] Check admin view shows completed status and data
- [ ] Test with required vs optional scenarios

### Survey System
- [ ] Create test survey with multiple questions
- [ ] Assign survey to test event
- [ ] Set as required before registration
- [ ] Register for event
- [ ] Verify survey link sent/displayed
- [ ] Complete survey as registrant
- [ ] Verify `evr_survey_completed` tracked
- [ ] View results in admin area
- [ ] Test with optional survey
- [ ] Test post-event survey timing

---

## Related Files

### Extra Info Collection
- `/adm/admin_event_edit.php` - Toggle setting
- `/adm/admin_event.php` - Display collected data
- `/logic/cart_charge_logic.php` - Post-purchase notification
- `/logic/profile_logic.php` - Profile reminders (commented)
- `/views/profile/profile.php` - Profile display (commented)
- `/views/profile/event_register_finish.php` - Collection form (incomplete?)
- `/data/events_class.php` - Event field definition
- `/data/event_registrants_class.php` - Registrant field definitions

### Survey System
- `/adm/admin_surveys.php` - Survey list
- `/adm/admin_survey.php` - Survey detail
- `/adm/admin_survey_edit.php` - Survey editor
- `/adm/admin_survey_answers.php` - View answers
- `/adm/admin_event_edit.php` - Event-survey link (commented out)
- `/logic/survey_logic.php` - Survey display logic
- `/views/survey_finish.php` - Survey completion page
- `/data/surveys_class.php` - Survey data class
- `/data/survey_questions_class.php` - Questions data class
- `/data/survey_answers_class.php` - Answers data class

---

## Open Questions

1. **Extra Info:** Should we make the fields customizable per event, or keep them standard?
2. **Survey Timing:** Should surveys be pre-event, post-event, or both (configurable)?
3. **Required Enforcement:** How strictly should "required" be enforced? Block event access?
4. **Merge vs Separate:** Long-term, should these systems be merged or kept distinct?
5. **Deadline Handling:** Should there be deadlines for completing extra info/surveys?
6. **Anonymous Surveys:** Should surveys support anonymous responses?
7. **Survey Reuse:** Can the same survey be used for multiple events?

---

## Implementation Priority

**High Priority:**
1. Finish extra info collection form (`event_register_finish`)
2. Uncomment and test profile reminders

**Medium Priority:**
3. Enable survey system in event edit form
4. Connect surveys to registration flow
5. Add survey completion tracking to event_registrants

**Low Priority (Future Enhancements):**
6. Make extra info fields customizable
7. Add survey timing options (pre/post event)
8. Advanced survey features (conditional questions, branching, etc.)
9. Survey result analytics/exports
