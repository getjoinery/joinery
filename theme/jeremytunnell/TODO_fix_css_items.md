# JeremyTunnell Theme - CSS Fixes Required

## Overview

This theme has 40 broken CSS references to missing assets from non-existent themes. These need to be fixed to restore proper styling functionality.

## Broken Dependencies Summary

- **34 missing button images** (roundedcorners theme references)
- **6 missing background patterns** (generic /theme/images/ references)

## 1. Missing Button Images (34 instances)

**File:** `/theme/jeremytunnell/styles/uni-form_24.css` (Lines 258-291)

### Complete list of broken button references:
- Line 258: `.buttonHolder button.btn_Login { background-image: url(/theme/roundedcorners/images/btn_login.png); width: 72px; height:28px; }`
- Line 261: `.buttonHolder button.btn_booknow { background-image: url(/theme/roundedcorners/images/btn_booknow_2.png); width: 87px; height:28px; }`
- Line 262: `.buttonHolder button.btn_buynow { background-image: url(/theme/roundedcorners/images/btn_buynow.png); width: 106px; height:27px; }`
- Line 263: `.buttonHolder button.btn_canceljob { background-image: url(/theme/roundedcorners/images/btn_canceljob.png); width:115px; height:27px; }`
- Line 264: `.buttonHolder button.btn_changepassword { background-image: url(/theme/roundedcorners/images/btn_changepassword.png); width:126px; height:22px; }`
- Line 265: `.buttonHolder button.btn_changepicture { background-image: url(/theme/roundedcorners/images/btn_changepicture.png); width:112px; height:22px; }`
- Line 266: `.buttonHolder button.btn_checkout{ background-image: url(/theme/roundedcorners/images/btn_checkout.png); width:103px; height:27px; }`
- Line 267: `.buttonHolder button.btn_confirmjob { background-image: url(/theme/roundedcorners/images/btn_confirmjob.png); width: 123px; height:27px; }`
- Line 268: `.buttonHolder button.btn_declinejob { background-image: url(/theme/roundedcorners/images/btn_declinejob.png); width:120px; height:27px; }`
- Line 269: `.buttonHolder button.btn_deletevideo { background-image: url(/theme/roundedcorners/images/btn_deletevideo.png); width:85px; height:23px; }`
- Line 270: `.buttonHolder button.btn_editinfo { background-image: url(/theme/roundedcorners/images/btn_editinfo.png); width:74px; height:22px; }`
- Line 271: `.buttonHolder button.btn_finish { background-image: url(/theme/roundedcorners/images/btn_finish_2.png); width:93px; height:27px; }`
- Line 272: `.buttonHolder button.btn_fixlater { background-image: url(/theme/roundedcorners/images/btn_fixlater_2.png); width:100px; height:27px; }`
- Line 273: `.buttonHolder button.btn_importcontacts { background-image: url(/theme/roundedcorners/images/btn_importcontacts_2.png); width:101px; height:22px; }`
- Line 274: `.buttonHolder button.btn_learnmore { background-image: url(/theme/roundedcorners/images/btn_learnmore.png); width:112px; height:28px; }`
- Line 275: `.buttonHolder button.btn_login { background-image: url(/theme/roundedcorners/images/btn_login_2.png); width: 93px; height:27px; }`
- Line 276: `.buttonHolder button.btn_next { background-image: url(/theme/roundedcorners/images/btn_next.png); width: 93px; height:27px; }`
- Line 277: `.buttonHolder button.btn_proceedtocheckout { background-image: url(/theme/roundedcorners/images/btn_proceedtocheckout.png); width:176px; height:28px; }`
- Line 278: `.buttonHolder button.btn_proceedtonextstep { background-image: url(/theme/roundedcorners/images/btn_proceedtonextstep.png); width:176px; height:28px; }`
- Line 279: `.buttonHolder button.btn_purchasenow { background-image: url(/theme/roundedcorners/images/btn_purchasenow.png); width:132px; height:28px; }`
- Line 280: `.buttonHolder button.btn_reply { background-image: url(/theme/roundedcorners/images/btn_reply_2.png); width: 93px; height:27px; }`
- Line 281: `.buttonHolder button.btn_requestanestimate { background-image: url(/theme/roundedcorners/images/btn_requestanestimate_2.png); width:180px; height:28px; }`
- Line 282: `.buttonHolder button.btn_Resend_gray { background-image: url(/theme/roundedcorners/images/btn_resend_2.png); width:85px; height:23px; }`
- Line 283: `.buttonHolder button.btn_save { background-image: url(/theme/roundedcorners/images/btn_save.png); width:93px; height:27px; }`
- Line 284: `.buttonHolder button.btn_sendamessage { background-image: url(/theme/roundedcorners/images/btn_sendamessage.png); width:150px; height:28px; }`
- Line 285: `.buttonHolder button.btn_sendemail { background-image: url(/theme/roundedcorners/images/btn_sendemail.png); width:113px; height:28px; }`
- Line 286: `.buttonHolder button.btn_sendemails { background-image: url(/theme/roundedcorners/images/btn_sendemails.png); width:118px; height:28px; }`
- Line 287: `.buttonHolder button.btn_submit { background-image: url(/theme/roundedcorners/images/btn_submit_2.png); width:93px; height:27px; }`
- Line 288: `.buttonHolder button.btn_submitanestimate { background-image: url(/theme/roundedcorners/images/btn_submitanestimate.png); width:180px; height:28px; }`
- Line 289: `.buttonHolder button.btn_updateterms { background-image: url(/theme/roundedcorners/images/btn_updateterms.png); width:133px; height:28px; }`
- Line 290: `.buttonHolder button.btn_updatetotal { background-image: url(/theme/roundedcorners/images/btn_updatetotal.png); width:84px; height:22px; }`
- Line 291: `.buttonHolder button.btn_verify { background-image: url(/theme/roundedcorners/images/btn_verify.png); width:93px; height:27px; }`

**Impact:** All 34 button styles are broken due to missing roundedcorners theme.

## 2. Missing Background Pattern Images (6 instances)

### File: `/theme/jeremytunnell/styles/uni-form_24.css`
- Line 361: `background:url(/theme/images/bg_callout_top.png) bottom left no-repeat;`
- Line 366: `background:url(/theme/images/bg_callout_pointer.png) left top no-repeat;`
- Line 380: `background:url(/theme/images/bg_callout_bottom.png) bottom left no-repeat;`

### File: `/theme/jeremytunnell/styles/uni-form-profile_4.css`
- Line 320: `background:url(/theme/images/bg_callout_top.png) bottom left no-repeat;`
- Line 325: `background:url(/theme/images/bg_callout_pointer.png) left top no-repeat;`
- Line 339: `background:url(/theme/images/bg_callout_bottom.png) bottom left no-repeat;`

**Impact:** Form callout background patterns are broken due to missing generic theme directory.

## Fix Options

### For Button Images (34 missing assets)
1. **Create new button images** matching the expected dimensions
2. **Replace with CSS-only buttons** using modern styling (recommended)
3. **Find/recreate original roundedcorners theme assets**

#### Recommended CSS replacement approach:
```css
/* Replace image-based buttons with CSS-styled buttons */
.buttonHolder button.btn_Login {
    background: linear-gradient(to bottom, #4a90e2, #357abd);
    border: 1px solid #2e6da4;
    color: white;
    width: 72px;
    height: 28px;
    border-radius: 4px;
}
/* Apply similar pattern to all 34 button styles */
```

### For Background Patterns (6 missing assets)
1. **Create directory structure:**
   ```bash
   mkdir -p "/theme/jeremytunnell/images"
   ```

2. **Options:**
   - Create placeholder background images
   - Replace with CSS gradients/patterns
   - Remove callout styles entirely

## Usage Context

The uni-form CSS files ARE actively used by this theme:
- `FormWriterPublic.php` generates HTML with `form_callout` classes
- The broken background images affect form hint callouts
- The broken button images affect form button styling
- These CSS files provide essential form styling functionality

## Priority

**High Priority** - These broken references cause missing visual elements in forms, affecting user experience and theme functionality.