# Pattern-to-Regex Consolidation Specification

## Overview
Consolidate duplicate regex pattern building logic in RouteHelper by extracting it into a shared private helper method. This eliminates ~15 lines of duplicate code between `matchesPattern()` and `extractRouteParams()` methods.

## The Problem: Duplicate Code

### Current extractRouteParams() Method (lines 664-693)
```php
public static function extractRouteParams($pattern, $path) {
    $params = [];
    
    // Normalize paths - ensure both have leading slash for comparison
    if ($pattern[0] !== '/') $pattern = '/' . $pattern;
    if ($path[0] !== '/') $path = '/' . $path;
    
    // Use preg_match with named capture groups
    $regex_pattern = preg_quote($pattern, '#');                    // ← DUPLICATE LINE 1
    
    // Replace semantic placeholders with named capture groups
    $regex_pattern = preg_replace('/\\\\{([^}]+)\\\\}/', '(?P<$1>[^/]+)', $regex_pattern);  // ← SIMILAR TO LINE 2
    
    // Replace wildcards with multi-segment captures  
    $regex_pattern = str_replace('\\\\*', '(.*)', $regex_pattern);  // ← DUPLICATE LINE 3
    
    $final_pattern = '#^' . $regex_pattern . '$#';                  // ← DUPLICATE LINE 4
    
    // Match against the path and extract named groups
    if (preg_match($final_pattern, $path, $matches)) {
        // Extract only named capture groups (not numbered ones)
        foreach ($matches as $key => $value) {
            if (!is_numeric($key)) {
                $params[$key] = $value;
            }
        }
    }
    
    return $params;
}
```

### Current matchesPattern() Method (lines 709-733)
```php
public static function matchesPattern($pattern, $path) {
    // Handle exact matches
    if ($pattern === $path) {
        return true;
    }
    
    // Handle patterns with wildcards or parameters
    if (strpos($pattern, '*') !== false || strpos($pattern, '{') !== false) {
        $regex_pattern = preg_quote($pattern, '#');                 // ← DUPLICATE LINE 1
        
        // Replace semantic placeholders (single segments)
        // NOTE: This hardcodes specific placeholder names, while extractRouteParams accepts ANY name!
        $regex_pattern = preg_replace('/\\\\{(plugin|theme|file|slug|id|path)\\\\}/', '([^/]+)', $regex_pattern);  // ← PROBLEM LINE
        
        // Replace wildcard with multi-segment match (everything from this point)
        $regex_pattern = str_replace('\\*', '(.*)', $regex_pattern); // ← DUPLICATE LINE 3
        
        $final_pattern = '#^' . $regex_pattern . '$#';               // ← DUPLICATE LINE 4
        
        $result = preg_match($final_pattern, $path);
        
        return $result;
    }
    
    return false;
}
```

## Key Problems Identified

### 1. **Exact Duplication**
Four lines are exactly duplicated between methods:
```php
$regex_pattern = preg_quote($pattern, '#');
$regex_pattern = str_replace('\\*', '(.*)', $regex_pattern);
$final_pattern = '#^' . $regex_pattern . '$#';
preg_match($final_pattern, ...)
```

### 2. **Inconsistent Placeholder Handling** 🔴
**CRITICAL BUG**: The methods handle placeholders differently!

**extractRouteParams()** accepts ANY placeholder name:
```php
// Matches {anything} - flexible and extensible
preg_replace('/\\\\{([^}]+)\\\\}/', '(?P<$1>[^/]+)', $regex_pattern);
```

**matchesPattern()** only accepts HARDCODED names:
```php
// Only matches {plugin}, {theme}, {file}, {slug}, {id}, or {path} - restrictive!
preg_replace('/\\\\{(plugin|theme|file|slug|id|path)\\\\}/', '([^/]+)', $regex_pattern);
```

This means:
- ✅ `extractRouteParams('/user/{username}', '/user/john')` works correctly
- ❌ `matchesPattern('/user/{username}', '/user/john')` FAILS because "username" isn't hardcoded!

### 3. **Different Capture Groups**
- `extractRouteParams()` uses named capture groups: `(?P<name>[^/]+)`
- `matchesPattern()` uses simple capture groups: `([^/]+)`

This is intentional (one extracts values, one just matches) but the logic should be centralized.

## Solution: Create buildRouteRegex() Method

### New Private Method
```php
/**
 * Build a regex pattern from a route pattern
 * 
 * Converts route patterns with placeholders and wildcards into regex patterns.
 * Centralizes the pattern conversion logic used by both matchesPattern() and extractRouteParams().
 * 
 * Examples:
 * - '/page/{slug}' becomes '#^/page/([^/]+)$#' (unnamed) or '#^/page/(?P<slug>[^/]+)$#' (named)
 * - '/admin/*' becomes '#^/admin/(.*)$#'
 * - '/user/{id}/posts/{postId}' works with ANY placeholder names
 * 
 * @param string $pattern Route pattern (e.g., '/page/{slug}', '/admin/*')
 * @param bool $named_groups Whether to use named capture groups (for parameter extraction)
 * @return string Regex pattern ready for preg_match
 */
private static function buildRouteRegex($pattern, $named_groups = false) {
    // Quote the pattern for regex safety
    $regex_pattern = preg_quote($pattern, '#');
    
    if ($named_groups) {
        // Replace semantic placeholders with named capture groups for extraction
        // This captures ANY placeholder name, not just predefined ones
        $regex_pattern = preg_replace('/\\\\{([^}]+)\\\\}/', '(?P<$1>[^/]+)', $regex_pattern);
    } else {
        // Replace semantic placeholders with simple capture groups for matching
        // Support ANY placeholder name, not just predefined ones
        // This fixes the bug where matchesPattern() only worked with hardcoded names
        $regex_pattern = preg_replace('/\\\\{[^}]+\\\\}/', '([^/]+)', $regex_pattern);
    }
    
    // Replace wildcards with multi-segment captures
    $regex_pattern = str_replace('\\*', '(.*)', $regex_pattern);
    
    // Return complete regex pattern with delimiters
    return '#^' . $regex_pattern . '$#';
}
```

## Refactored Methods

### Updated extractRouteParams()
```php
public static function extractRouteParams($pattern, $path) {
    $params = [];
    
    // Normalize paths - ensure both have leading slash for comparison
    if ($pattern[0] !== '/') $pattern = '/' . $pattern;
    if ($path[0] !== '/') $path = '/' . $path;
    
    // Build regex pattern with named capture groups
    $final_pattern = self::buildRouteRegex($pattern, true);
    
    // Match against the path and extract named groups
    if (preg_match($final_pattern, $path, $matches)) {
        // Extract only named capture groups (not numbered ones)
        foreach ($matches as $key => $value) {
            if (!is_numeric($key)) {
                $params[$key] = $value;
            }
        }
    }
    
    return $params;
}
```

### Updated matchesPattern()
```php
public static function matchesPattern($pattern, $path) {
    // Handle exact matches
    if ($pattern === $path) {
        return true;
    }
    
    // Handle patterns with wildcards or parameters
    if (strpos($pattern, '*') !== false || strpos($pattern, '{') !== false) {
        // Build regex pattern without named groups (just for matching)
        $final_pattern = self::buildRouteRegex($pattern, false);
        
        return (bool) preg_match($final_pattern, $path);
    }
    
    return false;
}
```

## Before/After Comparison

### Before: 52 total lines (both methods)
```
extractRouteParams(): 30 lines
matchesPattern(): 22 lines
Total: 52 lines with significant duplication
```

### After: 37 total lines (both methods + helper)
```
buildRouteRegex(): 20 lines (new)
extractRouteParams(): 20 lines (reduced from 30)
matchesPattern(): 14 lines (reduced from 22)
Total: 54 lines BUT -15 lines of duplication = 37 unique lines
```

**Net reduction: 15 lines of duplicate code eliminated**

## Files Affected

### /includes/RouteHelper.php
- Add new private method `buildRouteRegex()` (approximately 20 lines with documentation)
- Modify `extractRouteParams()` method (reduce by ~10 lines)
- Modify `matchesPattern()` method (reduce by ~8 lines)
- Net reduction: ~15-18 lines of duplicate code

## Implementation Steps

1. **Add buildRouteRegex() method** after the constructor or at the end of private methods section
2. **Update extractRouteParams()** to use buildRouteRegex()
3. **Update matchesPattern()** to use buildRouteRegex()
4. **Test all route patterns** to ensure behavior is preserved
5. **Verify bug fix**: Test that custom placeholder names now work in matchesPattern()

## Testing Checklist

Verify these patterns still work correctly:
- [ ] `/` - Homepage exact match
- [ ] `/admin` - Simple exact match  
- [ ] `/admin/*` - Wildcard matching
- [ ] `/page/{slug}` - Single parameter extraction
- [ ] `/plugins/{plugin}/admin/*` - Mixed parameter and wildcard
- [ ] `/theme/{theme}/assets/*` - Theme asset patterns
- [ ] `/item/{id}/edit/{action}` - Multiple parameters
- [ ] **NEW**: `/user/{username}` - Custom placeholder name (bug fix!)
- [ ] **NEW**: `/api/{version}/users/{userId}` - Multiple custom placeholders

## Benefits

1. **Code Reduction**: ~15-18 lines removed
2. **Bug Fix**: matchesPattern() now accepts ANY placeholder names, not just hardcoded ones
3. **Maintenance**: Single place to update pattern conversion logic
4. **Consistency**: Guaranteed same regex building between methods
5. **Testability**: Can unit test pattern conversion separately
6. **Future-proof**: Easy to add new placeholder types or pattern features

## Risks & Mitigation

### Risk: Regex pattern differences
**Mitigation**: The current logic is nearly identical between methods. The only intentional difference is named vs unnamed capture groups, which is handled by the `$named_groups` parameter.

### Risk: Breaking existing routes
**Mitigation**: Comprehensive testing of all route pattern types before deployment. The refactoring preserves exact same regex patterns (and actually fixes a bug).

### Risk: Performance impact
**Mitigation**: Method call overhead is negligible compared to regex compilation and matching. The consolidation may actually improve performance through better CPU cache usage.

## Bug Fix Bonus

This refactoring also fixes a limitation where `matchesPattern()` only worked with predefined placeholder names. After this change, developers can use any placeholder names they want:
- `/product/{productId}/review/{reviewId}` ✅
- `/api/{version}/endpoint/{method}` ✅  
- `/blog/{year}/{month}/{day}/{slug}` ✅

Currently these would fail in `matchesPattern()` because "productId", "reviewId", "version", "method", "year", "month", and "day" aren't in the hardcoded list!