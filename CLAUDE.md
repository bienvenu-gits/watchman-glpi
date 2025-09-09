# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a GLPI plugin named "Watchman" that provides CVE (Common Vulnerabilities and Exposures) monitoring and management capabilities. The plugin integrates with external vulnerability APIs to sync security alerts and manage the vulnerability lifecycle through GLPI's ticketing system.

## Development Environment

- **PHP Version**: 7.4+
- **Platform**: Windows (using WAMP)
- **Framework**: GLPI Plugin Architecture
- **Package Manager**: Composer

## Common Development Commands

Since this is a GLPI plugin, there are no specific build commands. Development follows GLPI's plugin conventions:

- Install dependencies: `composer install`
- Plugin is activated through GLPI's web interface

## Architecture

### Core Components

The plugin uses a layered architecture with clear separation of concerns:

#### Main Classes (src/)
- `WatchmanManager`: Main plugin manager and entry point
- `AlertManager`: Handles CVE alerts and vulnerability management
- `ComputerManager`: Extends GLPI Computer objects with CVE data
- `WatchmanConfig`: Configuration management for API keys and settings
- `WatchmanApiClient`: API client for external vulnerability services
- `CronSyncAlert`, `CronSyncComputer`: Automated synchronization services
- `CronManager`, `CronMigration`: Cron task management and database migrations

#### Frontend (front/)
- Follows GLPI conventions with `.php` for list views and `.form.php` for detail/edit views
- Key files: `watchmanmanager.php`, `alertmanager.php`, `computermanager.form.php`

#### Plugin Structure
- `setup.php`: Plugin configuration, version info, and GLPI integration hooks
- `hook.php`: Installation/uninstall logic and lifecycle management
- `watchman.xml`: Plugin metadata

### Database Architecture

The plugin creates several prefixed tables (`glpi_plugin_watchman_*`) for:
- Configuration storage (API keys, settings)
- CVE data and alerts
- Computer mappings and vulnerability tracking
- Sync logs and metrics
- Error logging

### Key Features

1. **CVE Monitoring**: Automated vulnerability detection and tracking
2. **Alert Management**: Security alert generation and lifecycle management
3. **Ticket Integration**: Automatic ticket creation for vulnerabilities
4. **Patch Tracking**: Status tracking for vulnerability remediation
5. **Computer Integration**: Enhanced Computer objects with security data
6. **API Synchronization**: Regular sync with external vulnerability databases

### Architectural Patterns

- **Repository Pattern**: Data access abstraction
- **Service Layer**: Business logic separation
- **DTO Pattern**: Data transfer objects for API communication
- **Cron Integration**: GLPI's built-in task scheduling
- **Hook System**: GLPI's plugin lifecycle hooks

## Development Guidelines

### File Naming Conventions
- Classes use PascalCase (e.g., `WatchmanManager`)
- Frontend files follow GLPI conventions (`object.php` for lists, `object.form.php` for forms)
- All plugin tables are prefixed with `glpi_plugin_watchman_`

### Namespace Structure
All classes use the `GlpiPlugin\Watchman` namespace structure.

### Database Migrations
Database changes are handled through GLPI's Migration class system. See `CronMigration.php` for examples.

### Configuration
Plugin configuration is centralized in `WatchmanConfig` class and stored in dedicated database tables.