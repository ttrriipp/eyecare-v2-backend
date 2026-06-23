# PROJECT_MASTER_SPEC.md

## Project

**Padilla Optical Clinic Management System (POCMS)**

A full-stack optical clinic platform consisting of:

* **Admin Web Application**
* **Customer Mobile Application**
* **Shared Backend API**

The system digitizes clinic operations including appointments, prescriptions, inventory, optical product management, ordering, billing, customer communication, notifications, AR frame try-on, and feedback management.

---

## Architecture

### Repositories

#### 1. Backend Repository

```text
pocms-backend
```

Responsibilities:

* REST API
* Authentication & Authorization
* Business Logic
* Database
* Notifications
* File Storage
* AR Asset Management

Stack:

* Laravel
* Filament Admin Panel
* MySQL
* Laravel Sanctum
* Laravel Queues
* Laravel Notifications

---

#### 2. Mobile Repository

```text
pocms-mobile
```

Responsibilities:

* Customer-facing Android application
* Appointment booking
* Product browsing
* AR Try-On
* Messaging
* Ordering
* Billing tracking
* Notifications
* Feedback submission

Stack:

* Android Kotlin
* MVVM
* Retrofit
* Jetpack Components

---

## User Roles

### Admin

Manages entire system.

Capabilities:

* User management
* Product management
* Inventory management
* Orders
* Billing
* Payments
* Reports
* Audit logs
* Notifications

---

### Staff

Clinic operations.

Capabilities:

* Manage appointments
* Create prescriptions
* Process orders
* Manage inventory
* Reply to messages
* Handle billing/payment workflows

---

### Customer

Mobile application user.

Capabilities:

* Register/Login
* Book appointments
* View prescriptions
* Browse products
* AR try-on
* Place orders
* View billing
* Send messages
* Receive notifications
* Submit feedback

---

## Core Modules

### Authentication & RBAC

* Users
* Roles
* Permissions
* Role Permissions

---

### Appointment Management

* Appointment booking
* Appointment status tracking
* Visit reasons
* Staff assignment

---

### Prescription Management

* Optical prescription records
* Prescription history/versioning
* Prescription expiration tracking

Fields include:

* OD/OS Sphere
* Cylinder
* Axis
* Add
* Prism
* Base
* PD

---

### Product Catalog

* Categories
* Brands
* Products
* Product Variants
* Product Images

Frame dimensions:

* Lens Width
* Bridge Width
* Temple Length

---

### Inventory Management

* Stock tracking
* Inventory movements
* Reorder thresholds
* Low stock alerts

Movement types:

* Restock
* Sale
* Adjustment
* Return

---

### AR Try-On

* Frame AR assets
* Customer try-on sessions
* No biometric storage

Store:

* AR assets
* Session analytics

Do NOT store:

* Face geometry
* Facial landmarks
* Biometric identifiers

---

### Ordering

* Orders
* Order Items
* Discount support
* Order status workflow

Order numbers:

```text
ORD-YYYY-XXXXXX
```

---

### Billing

* One billing per order
* Balance tracking
* Due dates
* Billing statuses

Billing numbers:

```text
BIL-YYYY-XXXXXX
```

---

### Payments

* Multiple payments per billing
* Partial payments
* Payment methods
* Payment statuses

Statuses:

* Posted
* Voided
* Reversed

---

### Notifications

Channels:

* SMS
* Email
* In-App

Features:

* Templates
* Delivery history
* Retry tracking

Examples:

* Appointment reminders
* Order ready alerts
* Billing reminders
* Low stock alerts

---

### Direct Messaging

Customer ↔ Staff communication.

Features:

* Conversations
* Messages
* Attachments

Supported attachments:

* Images
* PDFs

---

### Feedback & Ratings

Customer feedback.

Features:

* Ratings (1–5)
* Written feedback
* Staff replies

---

### Audit Logs

Track all important actions.

Examples:

* Product updates
* Inventory adjustments
* Payments
* Order changes
* User management

Store:

* Actor
* Action
* Entity
* Old values
* New values

---

## Database Principles

### Soft Deletes

Use:

```text
deleted_at
```

Never hard delete business records.

---

### Lookup Tables

Use lookup tables for:

* Statuses
* Movement Types
* Payment Methods
* Notification Channels

Avoid free-text enums where possible.

---

### Historical Snapshots

Store snapshots for:

* Prices
* Discounts
* Notifications

History must remain accurate even if source records change.

---

### Financial Integrity

Rules:

* Orders own billing
* Billing owns payments
* Payments are never deleted
* Payments may be voided/reversed

---

## File Storage

Store:

* Product Images
* AR Assets
* Message Attachments

Recommended:

```text
storage/app
```

Serve through authenticated endpoints when necessary.

---

## Security Requirements

* RBAC enforced server-side
* Sanctum authentication
* Password hashing
* Audit logging
* File validation
* Authorization policies
* No biometric persistence

---

## Non-Functional Requirements

### Performance

* API-first architecture
* Pagination for large datasets
* Queue notifications
* Lazy-load media

### Maintainability

* Modular Laravel architecture
* Repository/service separation where appropriate
* Consistent REST conventions

### Scalability

* Mobile and web consume same API
* Notification channels extensible
* Product catalog extensible
* AR assets versionable

---

## API Style

Base:

```text
/api/v1
```

Examples:

```text
POST   /auth/login
POST   /appointments
GET    /appointments

GET    /products
GET    /products/{id}

POST   /orders
GET    /orders

POST   /messages
GET    /conversations

POST   /feedbacks

GET    /notifications
```

---

## Out of Scope

* Biometric face storage
* Online payment gateway integration
* Multi-branch support
* Accounting system
* Warehouse management
* Desktop application
* iOS application

---

## Final Scope Summary

The system is a centralized optical clinic platform providing:

* Appointment Management
* Prescription Management
* Optical Product Management
* Inventory Control
* AR Frame Try-On
* Ordering
* Billing & Payments
* SMS/In-App Notifications
* Customer-Staff Messaging
* Feedback & Ratings
* Audit Logging
* Role-Based Access Control

Backend is shared by both the Filament admin web application and the Android Kotlin mobile application.
