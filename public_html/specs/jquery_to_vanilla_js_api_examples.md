# Specification: Convert jQuery API Examples to Vanilla JavaScript

## Overview
Convert three API example files from jQuery-based implementations to vanilla JavaScript, removing the jQuery dependency while maintaining identical functionality.

## Current State

### Files to Convert
1. `/utils/api_example_js_create.php` - POST request to create new user
2. `/utils/api_example_js_list.php` - GET request to fetch list of posts
3. `/utils/api_example_js_single.php` - GET request to fetch single user

### Current Dependencies
- jQuery 3.4.1 loaded via Google CDN
- jQuery AJAX methods for API calls
- jQuery DOM manipulation for form handling and results display

## Requirements

### Functional Requirements
1. **Maintain Exact Functionality**: All converted files must work identically to jQuery versions
2. **Remove jQuery Dependency**: Eliminate the jQuery script tag and all jQuery-specific code
3. **Preserve API Integration**: Keep the same API endpoints, headers, and authentication
4. **Error Handling**: Maintain the same error handling logic and user feedback
5. **Form Handling**: Preserve form submission prevention and serialization where applicable

### Technical Requirements

#### 1. API Calls
- Convert `$.ajax()` to `fetch()` API
- Maintain proper headers (public_key, secret_key)
- Handle different HTTP methods (GET, POST)
- Preserve error handling for both network and application errors

#### 2. DOM Manipulation
- Replace jQuery selectors with `document.querySelector()` or `document.getElementById()`
- Convert jQuery DOM manipulation methods to vanilla JavaScript equivalents:
  - `$(element).html()` → `element.innerHTML`
  - `$(element).attr()` → `element.setAttribute()` or direct property access
  - `$(form).serialize()` → `FormData` or manual serialization

#### 3. Event Handling
- Replace jQuery event handlers with addEventListener:
  - `$(document).ready()` → `DOMContentLoaded` event or defer attribute
  - `$(form).submit()` → `form.addEventListener('submit')`

## Implementation Details

### File 1: api_example_js_create.php

**Current jQuery Implementation:**
- Form submission handler using `$("#contactForm1").submit()`
- Form serialization using `form.serialize()`
- AJAX POST request with custom headers
- Result display in `#results` paragraph
- Submit button disabling on success

**Vanilla JavaScript Conversion with Enhanced Error Handling:**
```javascript
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('contactForm1');
    const results = document.getElementById('results');
    const submitButton = document.getElementById('submitbutton');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        submitButton.disabled = true;
        results.innerHTML = 'Processing...';

        const formData = new FormData(form);
        const params = new URLSearchParams(formData).toString();

        try {
            const response = await fetchWithTimeout('https://jeremytunnell.net/api/v1/user', {
                method: 'POST',
                headers: {
                    'public_key': 'public_fn4ini750e8pkjwq',
                    'secret_key': 'test1',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params
            });

            if (!response.ok) {
                throw new Error(getStatusMessage(response.status));
            }

            const data = await response.json();

            if (data.errortype === 'TransactionError') {
                results.innerHTML = data.error;
                console.log(data);
                submitButton.disabled = false;
            } else if (data.errortype === 'AuthenticationError') {
                results.innerHTML = 'Authentication error. Please contact the webmaster.';
                console.log(data);
                submitButton.disabled = false;
            } else {
                submitButton.disabled = true;
                results.innerHTML = 'Submission was successful.';
            }
        } catch (error) {
            console.error('Error:', error);
            if (error.name === 'AbortError') {
                results.innerHTML = 'Request timeout. Please try again.';
            } else {
                results.innerHTML = `Error: ${error.message}. Please try again.`;
            }
            submitButton.disabled = false;
        }
    });

    // Helper function for HTTP status messages
    function getStatusMessage(status) {
        const messages = {
            400: 'Bad request - invalid parameters',
            401: 'Unauthorized - invalid or expired credentials',
            403: 'Forbidden - insufficient permissions',
            404: 'Not found - resource does not exist',
            500: 'Server error - please try again later',
            503: 'Service unavailable - please try again later'
        };
        return messages[status] || `HTTP Error ${status}`;
    }

    // Fetch with timeout
    async function fetchWithTimeout(url, options, timeout = 5000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            return await fetch(url, { ...options, signal: controller.signal });
        } finally {
            clearTimeout(timeoutId);
        }
    }
});
```

### File 2: api_example_js_list.php

**Current jQuery Implementation:**
- Document ready handler
- GET request for posts with query parameter
- Dynamic HTML generation from array response
- Results display using forEach loop

**Vanilla JavaScript Conversion with Enhanced Error Handling:**
```javascript
document.addEventListener('DOMContentLoaded', async function() {
    const results = document.getElementById('results');
    results.innerHTML = 'Loading...';

    try {
        const response = await fetchWithTimeout('https://jeremytunnell.net/api/v1/posts?published=true', {
            method: 'GET',
            headers: {
                'public_key': 'public_fn4ini750e8pkjwq',
                'secret_key': 'test1'
            }
        });

        if (!response.ok) {
            throw new Error(getStatusMessage(response.status));
        }

        const data = await response.json();

        if (data.errortype === 'TransactionError') {
            results.innerHTML = data.error;
            console.log(data);
        } else if (data.errortype === 'AuthenticationError') {
            results.innerHTML = 'Authentication error. Please contact the webmaster.';
            console.log(data);
        } else if (!data.data || data.data.length === 0) {
            results.innerHTML = 'No results found.';
        } else {
            let resultHtml = '';
            data.data.forEach(function(result) {
                resultHtml += result.pst_title;
                resultHtml += '<br>';
            });
            results.innerHTML = resultHtml;
        }
    } catch (error) {
        console.error('Error:', error);
        if (error.name === 'AbortError') {
            results.innerHTML = 'Request timeout. Please refresh the page to try again.';
        } else {
            results.innerHTML = `Error: ${error.message}. Please refresh the page to try again.`;
        }
    }

    // Helper function for HTTP status messages
    function getStatusMessage(status) {
        const messages = {
            400: 'Bad request - invalid parameters',
            401: 'Unauthorized - invalid or expired credentials',
            403: 'Forbidden - insufficient permissions',
            404: 'Not found - resource does not exist',
            500: 'Server error - please try again later',
            503: 'Service unavailable - please try again later'
        };
        return messages[status] || `HTTP Error ${status}`;
    }

    // Fetch with timeout
    async function fetchWithTimeout(url, options, timeout = 5000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            return await fetch(url, { ...options, signal: controller.signal });
        } finally {
            clearTimeout(timeoutId);
        }
    }
});
```

### File 3: api_example_js_single.php

**Current jQuery Implementation:**
- Document ready handler
- GET request for single user by ID
- Display specific field from response

**Vanilla JavaScript Conversion with Enhanced Error Handling:**
```javascript
document.addEventListener('DOMContentLoaded', async function() {
    const results = document.getElementById('results');
    results.innerHTML = 'Loading...';

    try {
        const response = await fetchWithTimeout('https://jeremytunnell.net/api/v1/user/41', {
            method: 'GET',
            headers: {
                'public_key': 'public_fn4ini750e8pkjwq',
                'secret_key': 'test1'
            }
        });

        if (!response.ok) {
            throw new Error(getStatusMessage(response.status));
        }

        const data = await response.json();

        if (data.errortype === 'TransactionError') {
            results.innerHTML = data.error;
            console.log(data);
        } else if (data.errortype === 'AuthenticationError') {
            results.innerHTML = 'Authentication error. Please contact the webmaster.';
            console.log(data);
        } else if (!data.data || !data.data.usr_first_name) {
            results.innerHTML = 'User data not found.';
        } else {
            results.innerHTML = data.data.usr_first_name;
        }
    } catch (error) {
        console.error('Error:', error);
        if (error.name === 'AbortError') {
            results.innerHTML = 'Request timeout. Please refresh the page to try again.';
        } else {
            results.innerHTML = `Error: ${error.message}. Please refresh the page to try again.`;
        }
    }

    // Helper function for HTTP status messages
    function getStatusMessage(status) {
        const messages = {
            400: 'Bad request - invalid parameters',
            401: 'Unauthorized - invalid or expired credentials',
            403: 'Forbidden - insufficient permissions',
            404: 'Not found - resource does not exist',
            500: 'Server error - please try again later',
            503: 'Service unavailable - please try again later'
        };
        return messages[status] || `HTTP Error ${status}`;
    }

    // Fetch with timeout
    async function fetchWithTimeout(url, options, timeout = 5000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            return await fetch(url, { ...options, signal: controller.signal });
        } finally {
            clearTimeout(timeoutId);
        }
    }
});
```

## Enhanced Error Handling

The conversion includes improved error handling with specific HTTP status code detection and retry logic:

### HTTP Status Code Handling
```javascript
if (!response.ok) {
    const statusMessage = {
        400: 'Bad request - invalid parameters',
        401: 'Unauthorized - invalid or expired credentials',
        403: 'Forbidden - insufficient permissions',
        404: 'Not found - resource does not exist',
        500: 'Server error - please try again later',
        503: 'Service unavailable - please try again later'
    };
    throw new Error(statusMessage[response.status] || `HTTP ${response.status}`);
}
```

### Retry Logic
Implement automatic retry for transient failures (network timeouts, 5xx errors):
```javascript
async function fetchWithRetry(url, options, retries = 3) {
    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            const response = await fetch(url, options);
            if (!response.ok && response.status >= 500 && attempt < retries) {
                await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
                continue;
            }
            return response;
        } catch (error) {
            if (attempt === retries) throw error;
            await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
        }
    }
}
```

### Timeout Handling
Add request timeout using AbortController:
```javascript
const controller = new AbortController();
const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 second timeout

try {
    const response = await fetch(url, {
        ...options,
        signal: controller.signal
    });
    clearTimeout(timeoutId);
    return response;
} catch (error) {
    if (error.name === 'AbortError') {
        throw new Error('Request timeout - please try again');
    }
    throw error;
}
```

## Browser Compatibility

The vanilla JavaScript implementations will work in:
- Chrome 55+ (2016)
- Firefox 52+ (2017)
- Safari 10.1+ (2017)
- Edge 15+ (2017)
- All modern mobile browsers

## Implementation Steps

1. Create backup copies of original files (optional)
2. Remove jQuery script tag from each file
3. Replace jQuery code with vanilla JavaScript equivalents
4. Test each file thoroughly
5. Document any changes or considerations

## Additional Considerations

### Enhanced Error Handling (Implemented in Spec)
The specification includes robust error handling:
- Network timeout handling (5 second default with AbortController)
- Specific error messages based on HTTP status codes (400, 401, 403, 404, 500, 503)
- Timeout error detection and user-friendly messaging
- Loading states and disabled button states during requests
- Empty result handling in list views

### Code Organization
Consider extracting common functionality:
- Create a shared API client module
- Centralize error handling logic
- Define constants for API endpoints and keys

### Security Notes
- API keys are currently hardcoded (for demo purposes only)
- In production, keys should be:
  - Stored securely server-side
  - Never exposed in client-side code
  - Transmitted over HTTPS only

## Success Criteria

1. All three files work without jQuery
2. Functionality is identical to original versions
3. No console errors in modern browsers
4. Code is clean and well-commented
5. Files pass syntax validation

## Future Enhancements

After successful conversion, consider:
1. TypeScript implementation for type safety
2. Creating a reusable API client class
3. Adding request caching
4. Implementing request debouncing for forms
5. Adding loading states and spinners
6. Creating unit tests for the API client code