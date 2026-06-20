# ER-Diagramm — EEG-Plattform

Generiert aus `database/init.sql`. Aktuell halten wenn sich das Schema ändert.

```mermaid
erDiagram
    communities {
        uuid id PK
        text name
        text slug
        text marktpartner_id
        text zvr_number
        text address
        text logo_path
        text iban
        text bic
        int payment_days
        bool active
        timestamptz created_at
    }

    users {
        uuid id PK
        text email
        text password_hash
        text first_name
        text last_name
        bool active
        timestamptz created_at
        timestamptz last_login_at
        text reset_token
        timestamptz reset_token_expires
    }

    user_roles {
        uuid id PK
        uuid community_id FK
        uuid user_id FK
        text role
        timestamptz created_at
    }

    members {
        uuid id PK
        uuid community_id FK
        uuid user_id FK
        text salutation
        text first_name
        text last_name
        text company_name
        text address
        text zip
        text city
        text email
        text phone
        text invoice_name
        text invoice_uid
        text member_iban
        text member_bic
        date member_since
        date member_until
        text status
        text contract_bezug_status
        timestamptz contract_bezug_generated_at
        text contract_einspeisung_status
        timestamptz contract_einspeisung_generated_at
        timestamptz created_at
    }

    metering_points {
        uuid id PK
        uuid community_id FK
        uuid member_id FK
        text zaehlpunkt_nr
        text meter_code
        text type
        bool active
        date registered_at
        timestamptz created_at
    }

    esp_measurements {
        timestamptz time PK
        uuid community_id FK
        uuid metering_point_id FK
        int power_bezug_w
        int power_einspeisung_w
        bigint energy_bezug_wh
        bigint energy_einspeisung_wh
        text znr
    }

    eda_measurements {
        timestamptz time PK
        uuid community_id FK
        uuid metering_point_id FK
        text meter_code
        numeric kwh_erzeugung
        numeric kwh_teilnahme
        numeric kwh_ueberschuss
        numeric kwh_restueberschuss
        text quality
        text completeness
    }

    eda_imports {
        uuid id PK
        uuid community_id FK
        uuid imported_by FK
        text filename
        timestamptz period_from
        timestamptz period_to
        int records_imported
        jsonb warnings
        text status
        timestamptz imported_at
    }

    tariff_config {
        uuid id PK
        uuid community_id FK
        date valid_from
        numeric bezug_ct_kwh
        numeric einspeisung_ct_kwh
        numeric mitgliedsbeitrag_eur
        timestamptz created_at
    }

    tax_config {
        uuid id PK
        uuid community_id FK
        date valid_from
        text tax_model
        numeric tax_rate_percent
        text uid_number
        timestamptz created_at
    }

    billing_runs {
        uuid id PK
        uuid community_id FK
        text quartal
        date period_from
        date period_to
        date freigabe_nach
        text status
        jsonb completeness_check
        uuid released_by FK
        timestamptz released_at
        timestamptz created_at
    }

    invoices {
        uuid id PK
        uuid billing_run_id FK
        uuid community_id
        uuid member_id FK
        text rechnungsnummer
        numeric saldo_eur
        text pdf_path
        timestamptz sent_at
        timestamptz created_at
    }

    invoice_items {
        uuid id PK
        uuid invoice_id FK
        text type
        numeric kwh
        numeric rate_ct_kwh
        numeric months
        numeric amount_eur
    }

    communities ||--o{ user_roles : "hat Rollen"
    communities ||--o{ members : "hat Mitglieder"
    communities ||--o{ metering_points : "hat Zählpunkte"
    communities ||--o{ tariff_config : "hat Tarife"
    communities ||--o{ tax_config : "hat Steuermodell"
    communities ||--o{ billing_runs : "hat Abrechnungen"
    communities ||--o{ eda_imports : "hat EDA-Importe"

    users ||--o{ user_roles : "hat Rollen"
    users |o--o{ members : "ist Portal-User von"
    users |o--o{ eda_imports : "hat importiert"
    users |o--o{ billing_runs : "hat freigegeben"

    members ||--o{ metering_points : "hat Zählpunkte"
    members ||--o{ invoices : "hat Rechnungen"

    metering_points ||--o{ esp_measurements : "ESP32-Messdaten"
    metering_points ||--o{ eda_measurements : "EDA-Messdaten"

    billing_runs ||--o{ invoices : "enthält Rechnungen"
    invoices ||--o{ invoice_items : "hat Positionen"
```

## Rollen-Übersicht

| Rolle | community_id in Session | Zugriff |
|-------|------------------------|---------|
| `platform_admin` | NULL | Alle Communities (ohne RLS) |
| `manager` | UUID der eigenen EEG | Nur eigene Community (via RLS) |
| `member` | UUID der eigenen EEG | Nur eigene Community (via RLS) |

## Tabellen mit Row-Level Security

Alle Tabellen außer `communities`, `users`, `user_roles` haben RLS aktiviert.  
Policy: `community_id = current_setting('app.community_id', true)::uuid`
