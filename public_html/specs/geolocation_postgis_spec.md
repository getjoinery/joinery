# Geolocation & PostGIS Spec

**Purpose:** Restore and modernize the platform's geolocation capabilities. This is a core platform feature used by any location-aware functionality (member directories, event proximity, dating discovery, local search, etc.).

**Last Updated:** 2026-02-06

**Depends on:** Core `user_profiles` table (see `dating_platform_spec.md` section 1.1)
**Used by:** Dating plugin discovery engine, member directory, event proximity (future)

---

## 1. Overview

The platform previously had extensive geo infrastructure (PostGIS, GeoIP, Google Maps geocoding, zip code lookups). All of it is currently dead code -- functions return FALSE, reference missing database schemas, or call deprecated APIs. This spec defines the modern replacement.

**What we're building:**
1. PostGIS extension for spatial queries and indexing
2. Geography column on user profiles with GiST spatial index
3. Geocoding service to convert addresses into coordinates (full address when available, graceful degradation to city-level)
4. Helper class for geo operations (geocoding, distance, bounding box, privacy-safe display)
5. Cleanup of legacy dead code

**What we're NOT building:**
- GeoIP (IP-to-location) -- let users set their own city
- Zip code database -- not needed when geocoding from addresses/city names

---

## 2. PostGIS Setup

### 2.1 Extension Installation

```sql
CREATE EXTENSION IF NOT EXISTS postgis;
```

PostGIS is the industry standard for spatial data in PostgreSQL. It provides:
- `geography` type for storing lat/lng points on the earth's surface
- `ST_DWithin()` for index-assisted "within X distance" queries
- `ST_Distance()` for exact distance calculations
- GiST spatial indexing for fast proximity lookups

**Installation on server:**
```bash
# Ubuntu/Debian
sudo apt-get install postgresql-16-postgis-3
# (adjust version numbers to match installed PostgreSQL)
```

**Verification:**
```sql
SELECT PostGIS_Version();
-- Should return something like "3.4 USE_GEOS=1 USE_PROJ=1 USE_STATS=1"
```

### 2.2 Geography Column on User Profiles

The `user_profiles` table (defined in `dating_platform_spec.md` section 1.1) stores `latitude` and `longitude` as decimal fields. The geography column is derived from these.

**Schema addition:**
```sql
-- Add geography column (derived from lat/lng, not user-entered)
ALTER TABLE upr_user_profiles ADD COLUMN upr_geography geography(Point, 4326);

-- Create spatial index
CREATE INDEX idx_user_profiles_geography ON upr_user_profiles USING GIST (upr_geography);
```

**SRID 4326** = WGS 84, the standard GPS coordinate system. The `geography` type (not `geometry`) handles distance calculations on the earth's curved surface in meters.

### 2.3 Geography Column Maintenance

The geography column must stay in sync with lat/lng. This is handled in the model's `save()` method, keeping all logic in the application layer where it's visible and consistent with the rest of the codebase.

**In the model's `save()` override:**

Since `SystemBase::set()` treats values as literal data for prepared statements, and the geography column needs a PostGIS function call, the `save()` override handles it with a direct SQL UPDATE after the parent save completes.

Note: `prepare()` is not guaranteed to be called, so this must live in `save()`.

```php
public function save($debug = false) {
    // Let parent save handle all normal fields
    $result = parent::save($debug);

    // Then update geography from lat/lng
    $dblink = DbConnector::get_instance()->get_db_link();
    if ($this->get('upr_latitude') && $this->get('upr_longitude')) {
        $sql = "UPDATE upr_user_profiles
                SET upr_geography = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                WHERE upr_user_profile_id = ?";
        $q = $dblink->prepare($sql);
        $q->execute([$this->get('upr_longitude'), $this->get('upr_latitude'), $this->get('upr_user_profile_id')]);
    } else {
        $sql = "UPDATE upr_user_profiles SET upr_geography = NULL WHERE upr_user_profile_id = ?";
        $q = $dblink->prepare($sql);
        $q->execute([$this->get('upr_user_profile_id')]);
    }

    return $result;
}
```

The same pattern applies to the `Location` model when geo fields are added there.

### 2.4 Integration with Data Model System

The `update_database` system (`DatabaseUpdater.php`) needs small extensions to support PostGIS geography types. Here's what it does today and what needs to change:

**Column creation** (lines 181, 569): The type string from `$field_specifications` is passed directly to PostgreSQL. `geography(Point, 4326)` would create successfully -- no change needed here.

**Column validation/comparison has three gaps:**

1. **`translateDataTypes()` (line 1657):** Maps PostgreSQL's internal type names back to the spec format for comparison. Has no mapping for `geography` -- hits the `else` branch, echoes an ERROR, and returns null. The caller (lines 302-304, 608-610) catches this ERROR and **skips the column entirely** on every `update_database` run.

2. **Type parsing regex (line 594):** `preg_match('/^([a-zA-Z0-9_]+)(?:\((\d+)\))?/', ...)` extracts base type and optional `(number)` length. Works for `varchar(255)` but not `geography(Point, 4326)` -- the parenthesized part contains non-numeric characters.

3. **GiST spatial index:** `$field_specifications` only supports unique constraints, not index type selection (btree, gist, gin, etc.).

**Required changes to `DatabaseUpdater.php`:**

```php
// 1. Add to translateDataTypes() (after the 'character' case):
else if($data_type == 'USER-DEFINED'){
    // PostGIS geography/geometry types report as USER-DEFINED in information_schema
    // The actual type name is in udt_name column
    return 'geography';  // See note below about udt_name
}

// 2. Update the type parsing regex to handle complex parameterized types:
// Change from:
//   preg_match('/^([a-zA-Z0-9_]+)(?:\((\d+)\))?/', $field_type, $matches)
// To:
//   preg_match('/^([a-zA-Z0-9_]+)(?:\((.+)\))?/', $field_type, $matches)
// This allows (Point, 4326) as well as (255)

// 3. Update areTypesEquivalent() or the comparison logic to handle
//    geography types where the parameterized part isn't a simple length
```

**Important note on `information_schema`:** PostgreSQL reports PostGIS types as `USER-DEFINED` in `information_schema.columns.data_type`. The actual type name (`geography`) is in the `udt_name` column. The `translateDataTypes` query (line 473) currently only selects `data_type` -- it would need to also select `udt_name` to properly identify geography columns.

**Column specification:**
```php
// In UserProfile $field_specifications:
'upr_latitude' => array('type' => 'numeric(10,7)', 'is_nullable' => true),
'upr_longitude' => array('type' => 'numeric(10,7)', 'is_nullable' => true),
'upr_geography' => array('type' => 'geography(Point, 4326)', 'is_nullable' => true),
```

**GiST spatial index:** Since `$field_specifications` doesn't support index type selection, the spatial index is created via migration. This is reasonable -- it's an index, not a column or table, and it's analogous to installing the PostGIS extension itself.

```php
$migration = array();
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM pg_indexes WHERE indexname = 'idx_user_profiles_geography'";
$migration['migration_sql'] = 'CREATE INDEX idx_user_profiles_geography ON upr_user_profiles USING GIST (upr_geography);';
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

**Summary of changes needed:**
| What | Where | Scope |
|------|-------|-------|
| Column creation | Works already | No change |
| Type translation | `translateDataTypes()` + query | Add `USER-DEFINED`/`udt_name` handling |
| Type parsing regex | `validateExistingColumn()` + `modifyExistingColumn()` | Broaden regex for non-numeric type params |
| Type comparison | `areTypesEquivalent()` | Handle geography equivalence |
| Spatial index | Migration | One-time migration |

---

## 3. Geocoding

### 3.1 Service: OpenStreetMap Nominatim

**Why Nominatim:**
- Free, no API key, no billing
- Handles full addresses down to street level, and degrades gracefully to city-level when less detail is provided
- Well-documented, stable API
- No vendor lock-in

**Why not Google Maps:** The legacy code used the deprecated Google Maps Geocoding v2 API (`maps.google.com/maps/geo`). Google's current geocoding API requires billing and an API key.

**Why not GeoIP:** The old code had IP-to-location via a `geoip` schema. IP geolocation is inaccurate (often wrong city, always wrong for VPN users) and the databases go stale. Better to let users state their location explicitly.

### 3.2 Geocoding Flow

**When:** On save, whenever address/location fields change. Works for any entity with location data (user profiles, event locations, etc.).

**Full address example (most precise):**
```
User address: "123 Main St, Nashville, Tennessee, US"
           |
           v
  GeoHelper::geocode(street: "123 Main St", city: "Nashville", state: "Tennessee", country: "US")
           |
           v
  Nominatim API: GET /search?street=123+Main+St&city=Nashville&state=Tennessee&countrycodes=US&format=json&limit=1
           |
           v
  Response: { "lat": "36.1627", "lon": "-86.7816", "display_name": "123 Main St, Nashville, ..." }
           |
           v
  Store: upr_latitude = 36.1627, upr_longitude = -86.7816
  Model save() updates: upr_geography = ST_SetSRID(ST_MakePoint(-86.7816, 36.1627), 4326)::geography
```

**City-only example (graceful degradation):**
```
User only enters city: "Nashville", state: "Tennessee"
           |
           v
  GeoHelper::geocode(city: "Nashville", state: "Tennessee", country: "US")
           |
           v
  Nominatim API: GET /search?city=Nashville&state=Tennessee&countrycodes=US&format=json&limit=1
           |
           v
  Response: { "lat": "36.1622767", "lon": "-86.7742984" }  (city center)
```

The same method handles both cases. More address detail = more precise coordinates. City-only still works fine (resolves to city center).

**Privacy consideration:** Full-address geocoding produces coordinates near a user's front door. This precision is stored internally for accurate distance calculations, but is **never exposed** to other users. Public-facing features only show approximate distance ("3 miles away"), never coordinates. See section 3.6 for privacy controls.

### 3.3 GeoHelper Class

New helper class at `includes/GeoHelper.php`:

```php
class GeoHelper {

    /**
     * Geocode an address to lat/lng using Nominatim.
     * Accepts any combination of address components -- from full street address
     * down to city-only. More detail = more precise coordinates.
     *
     * @param array $address Associative array with any of:
     *   'street'  => '123 Main St'
     *   'city'    => 'Nashville'
     *   'state'   => 'Tennessee'
     *   'country' => 'US' (ISO 3166-1 alpha-2)
     *   'postalcode' => '37201'
     * @return array|false  ['latitude' => float, 'longitude' => float, 'display_name' => string, 'precision' => string]
     *                      precision is 'street', 'city', 'state', or 'country' based on what Nominatim matched
     *                      Returns FALSE if location not found
     */
    public static function geocode(array $address);

    /**
     * Convenience wrapper: geocode from an Address model object.
     * Pulls street, city, state, zip from the Address and geocodes.
     *
     * @param Address $address  Address model instance
     * @return array|false  Same as geocode()
     */
    public static function geocode_address(Address $address);

    /**
     * Calculate distance between two points in kilometers.
     * Uses PostGIS if available, falls back to Haversine.
     */
    public static function distance_km($lat1, $lng1, $lat2, $lng2);

    /**
     * Get bounding box for a center point + radius (for pre-filtering).
     * Returns ['min_lat', 'max_lat', 'min_lng', 'max_lng']
     */
    public static function bounding_box($lat, $lng, $radius_km);

    /**
     * Format a distance for display based on site locale.
     * Returns string like "3.2 mi" or "5.1 km"
     */
    public static function format_distance($distance_km);
}
```

**`geocode_address()` integration:** The existing `Address` model already stores `address1`, `address2`, `city`, `state`, and zip code. This convenience method pulls those fields and passes them to `geocode()`, making it easy to geocode any address in the system without manual field mapping.

### 3.4 Nominatim API Details

**Endpoint:** `https://nominatim.openstreetmap.org/search`

**Parameters (structured query -- preferred):**
| Parameter | Value | Notes |
|-----------|-------|-------|
| `street` | Street address | Optional, most precise when available |
| `city` | City name | Optional (but recommended minimum) |
| `state` | State/region | Optional |
| `postalcode` | Postal/zip code | Optional, improves accuracy |
| `countrycodes` | ISO 3166-1 alpha-2 | Optional, improves accuracy and speed |
| `format` | `json` | Response format |
| `limit` | `1` | Only need the top result |
| `addressdetails` | `1` | Returns structured address breakdown |

Nominatim accepts any combination of these. It returns the best match for whatever is provided. Full street address gives precise results; city-only gives city center. The `addressdetails=1` response includes a `type` field indicating match precision (e.g., `house`, `city`, `state`).

**Required headers:**
```
User-Agent: Joinery/1.0 (https://joinerytest.site; admin@joinerytest.site)
```
Nominatim requires a valid User-Agent identifying the application. Requests without one may be blocked.

**Rate limiting:**
- Maximum 1 request per second (Nominatim usage policy)
- Not a problem for profile saves (one geocode per profile update)
- For bulk operations (importing users), add a 1-second delay between requests
- Consider caching: if a user changes their bio but not their city, skip re-geocoding

**Error handling:**
- Empty results = address not found. Store NULL for lat/lng. Show user a message: "We couldn't find that location. Please check the address."
- HTTP error = service unavailable. Store NULL, log error, don't block the save. Retry on next save.
- Rate limit (HTTP 429) = back off. Queue for retry.

### 3.5 Settings

New settings for geocoding configuration:

| Setting | Default | Description |
|---------|---------|-------------|
| `geocoding_enabled` | `true` | Feature toggle |
| `geocoding_service` | `nominatim` | Future-proof: could swap to another service |
| `geocoding_user_agent` | `Joinery/1.0` | User-Agent string for Nominatim |

### 3.6 Privacy Controls

Full-address geocoding produces precise coordinates (within meters of the actual location). This is valuable for accurate distance calculations but must never be exposed to other users.

**Rules:**
1. **Coordinates are internal data.** Latitude, longitude, and the geography column are never included in API responses, JSON exports, or public profile views.
2. **Distance is the public output.** Other users only see approximate distance: "3 miles away", "12 km away". Never exact coordinates.
3. **Display rounding:** Distances are rounded for display -- under 1km show "< 1 km", 1-10km round to nearest integer, 10+ km round to nearest 5. This prevents triangulation.
4. **`export_as_array()` exclusion:** The `UserProfile` model's `export_as_array()` and `get_json()` methods must exclude `upr_latitude`, `upr_longitude`, and `upr_geography` from output. Distance is computed at query time and included as a computed field, not as stored coordinates.
5. **API exclusion:** The REST API must not return coordinate fields for user profiles. A `distance_km` computed field may be included when the requesting user's location is known.

**Address fields themselves** (street, city, state) follow the existing `profile_visibility` setting. If a user's profile is 'members_only', their city is only visible to logged-in members. Street address is never displayed publicly regardless of visibility setting -- it's only used for geocoding.

---

## 4. Distance Queries

### 4.1 Core Pattern: ST_DWithin + ST_Distance

```sql
-- Find users within X km of a point, sorted by distance
SELECT u.usr_user_id, u.usr_first_name, u.usr_last_name,
  ST_Distance(
    up.upr_geography,
    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
  ) / 1000 AS distance_km
FROM usr_users u
JOIN upr_user_profiles up ON u.usr_user_id = up.upr_usr_user_id
WHERE up.upr_geography IS NOT NULL
  AND ST_DWithin(
    up.upr_geography,
    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
    :radius_meters  -- e.g., 50000 for 50km
  )
ORDER BY distance_km ASC;
```

**How it works:**
1. `ST_DWithin` uses the GiST spatial index to quickly find all rows whose geography point is within `radius_meters` of the target point. This is an index scan, not a full table scan.
2. `ST_Distance` computes the exact great-circle distance in meters for the filtered results.
3. Dividing by 1000 converts to kilometers.

**Performance notes:**
- The GiST index makes `ST_DWithin` fast even on millions of rows
- `ST_Distance` is only computed for the rows that pass the `ST_DWithin` filter
- For very large result sets, add `LIMIT`/`OFFSET` or cursor-based pagination

### 4.2 Distance in PHP (fallback / display)

For cases where you need distance calculation without a database query (e.g., displaying distance on a cached profile card):

```php
// In GeoHelper class
public static function haversine_km($lat1, $lng1, $lat2, $lng2) {
    $earth_radius = 6371; // km
    $dlat = deg2rad($lat2 - $lat1);
    $dlng = deg2rad($lng2 - $lng1);
    $a = sin($dlat/2) * sin($dlat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dlng/2) * sin($dlng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}
```

### 4.3 Unit Display

Users should see distances in their preferred unit. Store internally in metric (km), display based on locale:

| Locale | Display |
|--------|---------|
| US, UK, Myanmar, Liberia | miles (km * 0.621371) |
| Everyone else | kilometers |

Setting: `distance_unit` with values `km` or `mi`, default based on site locale.

---

## 5. Locations Table Enhancement

The existing `loc_locations` table (event venues, offices) has no coordinate fields. As part of this work, add optional geo support:

**New fields on `loc_locations`:**
- `loc_latitude` (decimal, nullable)
- `loc_longitude` (decimal, nullable)
- `loc_geography` (geography(Point, 4326), nullable) -- updated in model `save()` override, same pattern as user_profiles

This enables "events near me" queries in the future without a separate implementation.

---

## 6. Legacy Code Cleanup

### 6.1 Important: What Stays

**The `usa_zip_code_id` field on addresses is ACTIVE and must NOT be removed.** It's a `varchar(10)` field on `usa_users_addrs` that stores the user's postal/zip code as plain text. It's used in address forms, display formatting, duplicate checking, and search filtering throughout the codebase (`address_class.php`, `address_edit_logic.php`, `admin_address_edit_logic.php`, `admin_orders.php`, etc.).

What's dead is the old `zips.zip_codes` **lookup table** (a separate schema with city/state/lat/lng keyed by zip code). That table and schema don't exist in the database. Two methods in `address_class.php` still reference it -- those are the dead code. The field itself and all its form/display/search usage stays.

### 6.2 Files With Dead Geo Code

**`includes/LibraryFunctions.php`:**
| Function | Lines | Status | Action |
|----------|-------|--------|--------|
| `TransformLatLonToProjected($lat, $lon)` | 715-739 | Would error (no PostGIS) | **Remove** -- replaced by geography column update in model save() |
| `GetLocationData($zip, $city, $state)` | 448-498 | Returns FALSE | **Remove** -- replaced by GeoHelper::geocode() |
| `getCityStateFromIP($ip)` | 530-560 | Returns FALSE | **Remove** -- not replacing IP geolocation |
| `GetTimezoneFromZipCode($zip)` | 741-759 | Would error (no table) | **Remove** -- references non-existent `zips.zip_codes` table |
| `getTimezoneFromPoint($lat, $lng)` | 700-713 | Active but unused | **Remove** -- earthtools.org API, unused |

**`data/address_class.php`:**
| Code | Lines | Status | Action |
|------|-------|--------|--------|
| `$google_address_precision` | 24-35 | Unused | **Remove** -- Google geocoding not used |
| `IsInMetroCode($addr, $code)` | 138-158 | Would error | **Remove** -- references non-existent `geoip` schema |
| `get_distance_between()` | 543-550 | Would error | **Remove** -- replaced by GeoHelper::distance_km() |
| `get_location()` | 552-558 | Would error | **Remove** -- references non-existent coordinate fields |
| `update_city_state_from_zip()` | 667-675 | Would error | **Remove** -- references non-existent `zips.zip_codes` table |
| `getPointFromAddress()` | 677-754 | Deprecated API | **Remove** -- Google Maps v2 geocoding, replaced by GeoHelper::geocode() |
| coordinate unsets in `export_as_array()` | 456-458 | Harmless | **Remove** -- unsets fields that don't exist |
| `CheckForDuplicate` zip lookup | ~494 | Would error | **Remove** -- the `zips.zip_codes` query inside this method. The duplicate checking logic itself stays, just remove the dead zip schema reference |

**Keep in `address_class.php`:** The `usa_zip_code_id` field definition, all form helpers (`get_form_fields`), display methods (`get_address_string`, `get_microformat`), and search filters that use `usa_zip_code_id` as a plain text field. These are all active.

**`data/location_info_data.php`:**
- Lines 24-200: Extensive commented-out geocoding pipeline
- **Action:** **Remove entire file** if nothing else uses it, or gut the commented code

**`includes/SessionControl.php`:**
- Lines 683-701: `set_location_data()`, `_set_location_data_array()`, `get_location_data()`
- **Action:** **Keep** -- useful for caching current user location in session. Update to work with new GeoHelper.

**`data/users_class.php`:**
- Line 378: `$address->update_coordinates()` commented out
- **Action:** **Remove** commented line

**`logic/register_logic.php`:**
- Line 101: References non-existent `zips.zip_codes` table
- **Action:** **Remove** the dead reference

### 6.3 Missing Functions to NOT Recreate

These functions were called but never defined. Do not recreate them -- the new GeoHelper class replaces their intended purpose:

| Missing Function | Replacement |
|-----------------|-------------|
| `LibraryFunctions::GetLocationInfoFromCache()` | Session-based caching in SessionControl |
| `LibraryFunctions::StoreLocationInfoInCache()` | Session-based caching in SessionControl |
| `LibraryFunctions::GetDistanceBetweenLocations()` | `GeoHelper::distance_km()` |
| `Address::update_coordinates()` | Geography update in model `save()` override |

### 6.4 Database Objects

**Verified:** No geo-related database objects exist on the current database. Specifically:
- No `geoip` schema (referenced by `getCityStateFromIP`, `IsInMetroCode`)
- No `zips` schema (referenced by `GetLocationData`, `GetTimezoneFromZipCode`, `update_city_state_from_zip`)
- No geo-related functions, extensions, or types
- Only the `public` schema exists

These schemas/tables were either never deployed to this database or were cleaned up previously. The cleanup is **code-only** -- no database drops needed.

---

## 7. Implementation Order

1. **Install PostGIS extension** on database
2. **Create GeoHelper class** with geocode() and distance methods
3. **Add geography column + spatial index** to user_profiles table (via migration)
4. **Add geo fields to locations table** (via $field_specifications for lat/lng, migration for geography column)
5. **Wire geocoding into profile save** -- when city/state/country changes, call GeoHelper::geocode()
6. **Clean up legacy code** -- remove dead functions, commented code, missing references
7. **Add distance queries** to member directory / dating discovery

Steps 1-5 are prerequisite for the dating plugin discovery engine. Step 6 is cleanup that can happen in parallel. Step 7 is the consumer -- it lives in the dating spec.

---

## 8. Deployment Notes

### 8.1 PostGIS Package

PostGIS must be installed on the server as a system package before `CREATE EXTENSION` works:

```bash
# Check what PostgreSQL version is installed
psql --version

# Install matching PostGIS package
sudo apt-get install postgresql-<version>-postgis-3

# Verify
psql -U postgres -d joinerytest -c "CREATE EXTENSION IF NOT EXISTS postgis;"
psql -U postgres -d joinerytest -c "SELECT PostGIS_Version();"
```

### 8.2 Docker Considerations

For the docker-prod server, the container image needs PostGIS. Use `postgis/postgis` image instead of plain `postgres`, or install the package in the Dockerfile:

```dockerfile
# Option A: Use PostGIS image
FROM postgis/postgis:16-3.4

# Option B: Add to existing postgres image
RUN apt-get update && apt-get install -y postgresql-16-postgis-3
```

### 8.3 Install Script Integration

The PostGIS setup should be added to `maintenance_scripts/install_tools/install.sh` or `_site_init.sh` so new deployments get it automatically.

---

## 9. Testing

### 9.1 PostGIS Function Tests

```sql
-- Verify extension works
SELECT ST_Distance(
  ST_SetSRID(ST_MakePoint(-86.7742984, 36.1622767), 4326)::geography,  -- Nashville
  ST_SetSRID(ST_MakePoint(-87.6297982, 41.8781136), 4326)::geography   -- Chicago
) / 1000 AS distance_km;
-- Expected: ~703 km

-- Verify ST_DWithin with index
SELECT ST_DWithin(
  ST_SetSRID(ST_MakePoint(-86.7742984, 36.1622767), 4326)::geography,
  ST_SetSRID(ST_MakePoint(-86.8, 36.2), 4326)::geography,
  10000  -- 10km
);
-- Expected: true
```

### 9.2 Geocoding Tests

```php
// Full address geocoding
$result = GeoHelper::geocode(['street' => '600 Charlotte Ave', 'city' => 'Nashville', 'state' => 'Tennessee', 'country' => 'US']);
assert($result !== false);
assert(abs($result['latitude'] - 36.16) < 0.05);  // Tighter tolerance for street-level
assert(abs($result['longitude'] - (-86.78)) < 0.05);

// City-only geocoding (graceful degradation)
$result = GeoHelper::geocode(['city' => 'Nashville', 'state' => 'Tennessee', 'country' => 'US']);
assert($result !== false);
assert(abs($result['latitude'] - 36.16) < 0.1);
assert(abs($result['longitude'] - (-86.77)) < 0.1);

// Minimal input -- city only
$result = GeoHelper::geocode(['city' => 'London', 'country' => 'GB']);
assert($result !== false);

// Unknown address
$result = GeoHelper::geocode(['city' => 'Notarealcity', 'state' => 'Notastate', 'country' => 'XX']);
assert($result === false);

// geocode_address() convenience method
$address = new Address($address_id, TRUE);
$result = GeoHelper::geocode_address($address);
assert($result !== false || $address->get('usa_city') === null);  // FALSE only if no city
```

### 9.3 Distance Calculation Tests

```php
// Nashville to Chicago ~703 km
$d = GeoHelper::distance_km(36.1622767, -86.7742984, 41.8781136, -87.6297982);
assert($d > 690 && $d < 720);

// Same point = 0
$d = GeoHelper::distance_km(36.16, -86.77, 36.16, -86.77);
assert($d === 0.0);
```
