# Photo Private Gallery

A custom WordPress plugin that adds paid, per-listing access controls to Directorist private galleries.

## Features

- Configurable Directorist plan and payment-button text
- Blurred private-gallery preview for visitors
- Signed listing and plan parameters through registration and checkout
- Automatic login after registration for the private-gallery checkout flow
- Per-user, per-listing entitlements based on paid Directorist orders
- Paid Private Galleries tab in the Directorist user dashboard
- Protected media delivery for entitled users

## Requirements

- WordPress 6.5 or later
- PHP 7.4 or later
- Directorist with the required pricing and gallery functionality

## Server configuration

The `wp-content/uploads/ppg-private/` directory must be denied from direct web access. Private files are delivered through the plugin's entitlement-aware endpoint.

