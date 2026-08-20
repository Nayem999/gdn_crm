---
paths:
  - 'tests/**'
---

# Tests

## RefreshDatabase is enabled globally for Feature tests
tests/Pest.php applies Illuminate\Foundation\Testing\RefreshDatabase to every test in tests/Feature. Do not add it per-file; it's already global. Tests run against the sqlite connection forced in phpunit.xml (MySQL is for real dev/prod only, never for the test suite, per CRM_BUILD.md).
