Below is the **complete finalized schema** based on all decisions made, including:

* RBAC
* Appointments
* Optical Prescriptions
* Product Catalog
* Inventory
* Orders
* Billing
* Payments
* Notifications
* Messaging
* Feedback & Ratings
* AR Try-On
* Audit Logs

---

# ACCESS CONTROL

## roles

| Column      | Type               |
| ----------- | ------------------ |
| id          | BIGINT PK          |
| role_name   | VARCHAR(50) UNIQUE |
| description | TEXT               |
| created_at  | TIMESTAMP          |
| updated_at  | TIMESTAMP          |

---

## permissions

| Column          | Type                |
| --------------- | ------------------- |
| id              | BIGINT PK           |
| permission_name | VARCHAR(100) UNIQUE |
| description     | TEXT                |
| created_at      | TIMESTAMP           |
| updated_at      | TIMESTAMP           |

---

## role_permissions

| Column        | Type      |
| ------------- | --------- |
| role_id       | BIGINT FK |
| permission_id | BIGINT FK |

PK:

```sql
(role_id, permission_id)
```

---

## users

| Column        | Type                |
| ------------- | ------------------- |
| id            | BIGINT PK           |
| role_id       | BIGINT FK           |
| first_name    | VARCHAR(100)        |
| last_name     | VARCHAR(100)        |
| email         | VARCHAR(255) UNIQUE |
| password_hash | VARCHAR(255)        |
| phone_number  | VARCHAR(20)         |
| is_active     | BOOLEAN             |
| deleted_at    | TIMESTAMP NULL      |
| created_at    | TIMESTAMP           |
| updated_at    | TIMESTAMP           |

---

# APPOINTMENTS

## visit_reasons

| Column      | Type         |
| ----------- | ------------ |
| id          | BIGINT PK    |
| reason_name | VARCHAR(100) |
| description | TEXT         |
| is_active   | BOOLEAN      |
| created_at  | TIMESTAMP    |
| updated_at  | TIMESTAMP    |

---

## appointment_statuses

| Column      | Type        |
| ----------- | ----------- |
| id          | BIGINT PK   |
| status_name | VARCHAR(50) |
| is_active   | BOOLEAN     |
| created_at  | TIMESTAMP   |
| updated_at  | TIMESTAMP   |

---

## appointments

| Column                | Type               |
| --------------------- | ------------------ |
| id                    | BIGINT PK          |
| appointment_number    | VARCHAR(50) UNIQUE |
| customer_id           | BIGINT FK          |
| assigned_staff_id     | BIGINT FK NULL     |
| visit_reason_id       | BIGINT FK          |
| appointment_status_id | BIGINT FK          |
| appointment_date      | DATE               |
| appointment_time      | TIME               |
| notes                 | TEXT               |
| deleted_at            | TIMESTAMP NULL     |
| created_at            | TIMESTAMP          |
| updated_at            | TIMESTAMP          |

---

# PRODUCT CATALOG

## product_categories

| Column        | Type         |
| ------------- | ------------ |
| id            | BIGINT PK    |
| category_name | VARCHAR(100) |
| description   | TEXT         |
| is_active     | BOOLEAN      |
| created_at    | TIMESTAMP    |
| updated_at    | TIMESTAMP    |

---

## brands

| Column      | Type         |
| ----------- | ------------ |
| id          | BIGINT PK    |
| brand_name  | VARCHAR(100) |
| description | TEXT         |
| is_active   | BOOLEAN      |
| created_at  | TIMESTAMP    |
| updated_at  | TIMESTAMP    |

---

## products

| Column       | Type           |
| ------------ | -------------- |
| id           | BIGINT PK      |
| category_id  | BIGINT FK      |
| brand_id     | BIGINT FK      |
| product_name | VARCHAR(255)   |
| description  | TEXT           |
| is_active    | BOOLEAN        |
| deleted_at   | TIMESTAMP NULL |
| created_at   | TIMESTAMP      |
| updated_at   | TIMESTAMP      |

---

## lens_types

| Column         | Type         |
| -------------- | ------------ |
| id             | BIGINT PK    |
| lens_type_name | VARCHAR(100) |
| description    | TEXT         |
| is_active      | BOOLEAN      |
| created_at     | TIMESTAMP    |
| updated_at     | TIMESTAMP    |

Examples:

```text
Single Vision
Bifocal
Progressive
Photochromic
Blue Light
```

---

## product_variants

| Column              | Type                |
| ------------------- | ------------------- |
| id                  | BIGINT PK           |
| product_id          | BIGINT FK           |
| lens_type_id        | BIGINT FK NULL      |
| sku                 | VARCHAR(100) UNIQUE |
| variant_name        | VARCHAR(255)        |
| color               | VARCHAR(100)        |
| lens_width_mm       | DECIMAL(5,2)        |
| bridge_width_mm     | DECIMAL(5,2)        |
| temple_length_mm    | DECIMAL(5,2)        |
| selling_price       | DECIMAL(10,2)       |
| stock_quantity      | INT                 |
| minimum_stock_level | INT                 |
| is_active           | BOOLEAN             |
| deleted_at          | TIMESTAMP NULL      |
| created_at          | TIMESTAMP           |
| updated_at          | TIMESTAMP           |

---

## product_images

| Column        | Type         |
| ------------- | ------------ |
| id            | BIGINT PK    |
| variant_id    | BIGINT FK    |
| image_path    | VARCHAR(500) |
| display_order | INT          |
| is_primary    | BOOLEAN      |
| created_at    | TIMESTAMP    |

---

## ar_assets

| Column        | Type         |
| ------------- | ------------ |
| id            | BIGINT PK    |
| variant_id    | BIGINT FK    |
| asset_type    | VARCHAR(30)  |
| asset_path    | VARCHAR(500) |
| asset_version | VARCHAR(30)  |
| is_active     | BOOLEAN      |
| created_at    | TIMESTAMP    |
| updated_at    | TIMESTAMP    |

---

# INVENTORY

## inventory_movement_types

| Column     | Type        |
| ---------- | ----------- |
| id         | BIGINT PK   |
| type_name  | VARCHAR(50) |
| created_at | TIMESTAMP   |
| updated_at | TIMESTAMP   |

---

## inventory_movements

| Column           | Type        |
| ---------------- | ----------- |
| id               | BIGINT PK   |
| variant_id       | BIGINT FK   |
| movement_type_id | BIGINT FK   |
| quantity         | INT         |
| previous_stock   | INT         |
| new_stock        | INT         |
| reference_type   | VARCHAR(50) |
| reference_id     | BIGINT      |
| remarks          | TEXT        |
| created_by       | BIGINT FK   |
| created_at       | TIMESTAMP   |

---

# PRESCRIPTIONS

## prescriptions

| Column                   | Type           |
| ------------------------ | -------------- |
| id                       | BIGINT PK      |
| customer_id              | BIGINT FK      |
| previous_prescription_id | BIGINT FK NULL |
| created_by               | BIGINT FK      |
| od_sphere                | DECIMAL(5,2)   |
| od_cylinder              | DECIMAL(5,2)   |
| od_axis                  | INT            |
| od_add                   | DECIMAL(5,2)   |
| od_prism                 | DECIMAL(5,2)   |
| od_base                  | VARCHAR(20)    |
| os_sphere                | DECIMAL(5,2)   |
| os_cylinder              | DECIMAL(5,2)   |
| os_axis                  | INT            |
| os_add                   | DECIMAL(5,2)   |
| os_prism                 | DECIMAL(5,2)   |
| os_base                  | VARCHAR(20)    |
| pd                       | DECIMAL(5,2)   |
| prescribed_at            | DATE           |
| expires_at               | DATE           |
| notes                    | TEXT           |
| created_at               | TIMESTAMP      |
| updated_at               | TIMESTAMP      |

---

# DISCOUNTS

## discounts

| Column            | Type          |
| ----------------- | ------------- |
| id                | BIGINT PK     |
| discount_name     | VARCHAR(100)  |
| discount_type     | VARCHAR(20)   |
| discount_value    | DECIMAL(10,2) |
| description       | TEXT          |
| requires_approval | BOOLEAN       |
| is_active         | BOOLEAN       |
| created_at        | TIMESTAMP     |
| updated_at        | TIMESTAMP     |

---

# ORDERS

## order_statuses

| Column        | Type        |
| ------------- | ----------- |
| id            | BIGINT PK   |
| status_name   | VARCHAR(50) |
| display_order | INT         |
| is_active     | BOOLEAN     |
| created_at    | TIMESTAMP   |
| updated_at    | TIMESTAMP   |

---

## orders

| Column                  | Type               |
| ----------------------- | ------------------ |
| id                      | BIGINT PK          |
| order_number            | VARCHAR(50) UNIQUE |
| customer_id             | BIGINT FK          |
| appointment_id          | BIGINT FK NULL     |
| prescription_id         | BIGINT FK NULL     |
| discount_id             | BIGINT FK NULL     |
| discount_type_snapshot  | VARCHAR(20)        |
| discount_value_snapshot | DECIMAL(10,2)      |
| discount_amount         | DECIMAL(10,2)      |
| order_status_id         | BIGINT FK          |
| subtotal                | DECIMAL(10,2)      |
| total_amount            | DECIMAL(10,2)      |
| confirmed_at            | TIMESTAMP NULL     |
| completed_at            | TIMESTAMP NULL     |
| created_at              | TIMESTAMP          |
| updated_at              | TIMESTAMP          |

---

## order_items

| Column     | Type          |
| ---------- | ------------- |
| id         | BIGINT PK     |
| order_id   | BIGINT FK     |
| variant_id | BIGINT FK     |
| quantity   | INT           |
| unit_price | DECIMAL(10,2) |
| subtotal   | DECIMAL(10,2) |
| created_at | TIMESTAMP     |

---

# BILLING

## billing_statuses

| Column      | Type        |
| ----------- | ----------- |
| id          | BIGINT PK   |
| status_name | VARCHAR(50) |
| is_active   | BOOLEAN     |
| created_at  | TIMESTAMP   |
| updated_at  | TIMESTAMP   |

---

## billings

| Column            | Type               |
| ----------------- | ------------------ |
| id                | BIGINT PK          |
| order_id          | BIGINT FK UNIQUE   |
| billing_number    | VARCHAR(50) UNIQUE |
| total_amount      | DECIMAL(10,2)      |
| amount_paid       | DECIMAL(10,2)      |
| balance_due       | DECIMAL(10,2)      |
| billing_status_id | BIGINT FK          |
| due_date          | DATE NULL          |
| created_at        | TIMESTAMP          |
| updated_at        | TIMESTAMP          |

---

# PAYMENTS

## payment_methods

| Column      | Type        |
| ----------- | ----------- |
| id          | BIGINT PK   |
| method_name | VARCHAR(50) |
| is_active   | BOOLEAN     |
| created_at  | TIMESTAMP   |
| updated_at  | TIMESTAMP   |

---

## payment_statuses

| Column      | Type        |
| ----------- | ----------- |
| id          | BIGINT PK   |
| status_name | VARCHAR(50) |
| is_active   | BOOLEAN     |
| created_at  | TIMESTAMP   |
| updated_at  | TIMESTAMP   |

---

## payments

| Column             | Type               |
| ------------------ | ------------------ |
| id                 | BIGINT PK          |
| billing_id         | BIGINT FK          |
| payment_method_id  | BIGINT FK          |
| payment_status_id  | BIGINT FK          |
| payment_reference  | VARCHAR(50) UNIQUE |
| external_reference | VARCHAR(100) NULL  |
| amount_paid        | DECIMAL(10,2)      |
| payment_date       | TIMESTAMP          |
| received_by        | BIGINT FK          |
| remarks            | TEXT               |
| created_at         | TIMESTAMP          |

---

# NOTIFICATIONS

## notification_templates

| Column            | Type         |
| ----------------- | ------------ |
| id                | BIGINT PK    |
| template_name     | VARCHAR(100) |
| notification_type | VARCHAR(50)  |
| subject           | VARCHAR(255) |
| message_body      | TEXT         |
| is_active         | BOOLEAN      |
| created_at        | TIMESTAMP    |
| updated_at        | TIMESTAMP    |

---

## notification_channels

| Column       | Type        |
| ------------ | ----------- |
| id           | BIGINT PK   |
| channel_name | VARCHAR(50) |
| is_active    | BOOLEAN     |
| created_at   | TIMESTAMP   |
| updated_at   | TIMESTAMP   |

---

## notification_statuses

| Column      | Type        |
| ----------- | ----------- |
| id          | BIGINT PK   |
| status_name | VARCHAR(50) |
| is_active   | BOOLEAN     |
| created_at  | TIMESTAMP   |
| updated_at  | TIMESTAMP   |

---

## notifications

| Column                  | Type              |
| ----------------------- | ----------------- |
| id                      | BIGINT PK         |
| user_id                 | BIGINT FK         |
| template_id             | BIGINT FK NULL    |
| notification_channel_id | BIGINT FK         |
| notification_status_id  | BIGINT FK         |
| subject_snapshot        | VARCHAR(255)      |
| message_snapshot        | TEXT              |
| related_entity_type     | VARCHAR(50)       |
| related_entity_id       | BIGINT            |
| sms_provider_reference  | VARCHAR(255) NULL |
| attempt_count           | INT               |
| last_attempt_at         | TIMESTAMP NULL    |
| sent_at                 | TIMESTAMP NULL    |
| delivered_at            | TIMESTAMP NULL    |
| created_at              | TIMESTAMP         |

---

# DIRECT MESSAGING

## conversations

| Column              | Type         |
| ------------------- | ------------ |
| id                  | BIGINT PK    |
| created_by          | BIGINT FK    |
| subject             | VARCHAR(255) |
| related_entity_type | VARCHAR(50)  |
| related_entity_id   | BIGINT       |
| created_at          | TIMESTAMP    |
| updated_at          | TIMESTAMP    |

---

## conversation_participants

| Column          | Type      |
| --------------- | --------- |
| conversation_id | BIGINT FK |
| user_id         | BIGINT FK |
| joined_at       | TIMESTAMP |

PK:

```sql
(conversation_id, user_id)
```

---

## messages

| Column          | Type           |
| --------------- | -------------- |
| id              | BIGINT PK      |
| conversation_id | BIGINT FK      |
| sender_id       | BIGINT FK      |
| message_body    | TEXT           |
| is_read         | BOOLEAN        |
| read_at         | TIMESTAMP NULL |
| created_at      | TIMESTAMP      |

---

## message_attachments

| Column            | Type         |
| ----------------- | ------------ |
| id                | BIGINT PK    |
| message_id        | BIGINT FK    |
| original_filename | VARCHAR(255) |
| stored_filename   | VARCHAR(255) |
| file_path         | VARCHAR(500) |
| mime_type         | VARCHAR(100) |
| file_size_bytes   | BIGINT       |
| uploaded_at       | TIMESTAMP    |

---

# FEEDBACK & RATINGS

## feedbacks

| Column         | Type           |
| -------------- | -------------- |
| id             | BIGINT PK      |
| customer_id    | BIGINT FK      |
| appointment_id | BIGINT FK NULL |
| order_id       | BIGINT FK NULL |
| rating         | TINYINT        |
| feedback_text  | TEXT           |
| is_anonymous   | BOOLEAN        |
| created_at     | TIMESTAMP      |

Constraint:

```text
rating BETWEEN 1 AND 5
```

---

## feedback_replies

| Column      | Type      |
| ----------- | --------- |
| id          | BIGINT PK |
| feedback_id | BIGINT FK |
| staff_id    | BIGINT FK |
| reply_text  | TEXT      |
| created_at  | TIMESTAMP |

---

# AUDIT LOGS

## audit_logs

| Column      | Type           |
| ----------- | -------------- |
| id          | BIGINT PK      |
| user_id     | BIGINT FK NULL |
| action      | VARCHAR(50)    |
| entity_type | VARCHAR(50)    |
| entity_id   | BIGINT         |
| old_values  | JSON           |
| new_values  | JSON           |
| ip_address  | VARCHAR(45)    |
| user_agent  | TEXT           |
| created_at  | TIMESTAMP      |

---

# AR ANALYTICS

## ar_tryon_sessions

| Column             | Type           |
| ------------------ | -------------- |
| id                 | BIGINT PK      |
| user_id            | BIGINT FK NULL |
| variant_id         | BIGINT FK      |
| session_started_at | TIMESTAMP      |
| session_ended_at   | TIMESTAMP NULL |
| duration_seconds   | INT            |
| created_at         | TIMESTAMP      |

---

# FINAL TOTAL

| Module          | Tables |
| --------------- | -----: |
| Access Control  |      4 |
| Appointments    |      3 |
| Product Catalog |      6 |
| Inventory       |      2 |
| Prescriptions   |      1 |
| Discounts       |      1 |
| Orders          |      3 |
| Billing         |      2 |
| Payments        |      3 |
| Notifications   |      4 |
| Messaging       |      4 |
| Feedback        |      2 |
| Audit           |      1 |
| AR              |      1 |

**Total: 37 Tables**

This is a complete, ERD-ready schema for your optical clinic management system covering all stated requirements.
