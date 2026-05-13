# Changelog

## [3.0.0] - 2026-XX-XX
Initial release. This is a standalone module for PrestaShop 8.0+ (including PS 9), built based on the Omniva Shipping v2.3.5 module.

### Features
- PrestaShop 8.0 and 9.x support
- Symfony/XLIFF translation system
- Modern hooks: `displayCarrierExtraContent`, `displayAdminOrderMain`, `actionEmailSendBefore`
- Dedicated `OmnivaLocations` class for terminal/parcel machine location management
- Automatic cleanup of obsolete files during upgrade
- PHP 7.4+ codebase (typed properties, strict types)
