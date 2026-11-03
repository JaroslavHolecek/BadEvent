BEGIN;

-- =========================================================
-- Core person and contacts
-- =========================================================

-- Type of contact
-- For example: phone, email, Discord, ...
CREATE TABLE contact_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    abbreviation VARCHAR(20) UNIQUE,
    deactivated_at TIMESTAMP
);

-- Entity that need not be part of organization, anybody whose information is needed
-- can be institution as well, i can not figure out any better name for this table
CREATE TABLE persons (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    short_name VARCHAR(100),
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP,
    deleted_at TIMESTAMP
);
CREATE OR REPLACE FUNCTION soft_delete_person(p_id INTEGER)
RETURNS VOID AS $$
BEGIN
    UPDATE persons
    SET
        full_name = 'deleted_person_' || p_id,
        short_name = NULL,
        note = NULL,
        deleted_at = CURRENT_TIMESTAMP
    WHERE id = p_id;

    DELETE FROM contacts
    WHERE person_id = p_id;

    UPDATE app_users
    SET note = NULL
    WHERE person_id = p_id;

    UPDATE donors
    SET note = NULL
    WHERE person_id = p_id;
END;
$$ LANGUAGE plpgsql;

-- Contacts for person
CREATE TABLE contacts (
    id SERIAL PRIMARY KEY,
    person_id INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
    contact_type_id INTEGER NOT NULL REFERENCES contact_types(id),
    value VARCHAR(255) NOT NULL,
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP
);

CREATE INDEX idx_contacts_person_id ON contacts(person_id);

-- =========================================================
-- Roles, operations and permissions
-- =========================================================

-- Operation in app. Those operations are assigned for Role of user
CREATE TABLE operations (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE, -- for example "Create event"
    code VARCHAR(100) NOT NULL UNIQUE, -- for example event.create
    note TEXT,
    deactivated_at TIMESTAMP
);

-- Roles that can be assigned to users
CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    code VARCHAR(100) NOT NULL UNIQUE,
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP
);

-- Operations assigned/allowed for Role
CREATE TABLE role_operations (
    id SERIAL PRIMARY KEY,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    operation_id INTEGER NOT NULL REFERENCES operations(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP,
    UNIQUE (role_id, operation_id)
);

CREATE INDEX idx_role_operations_role_id ON role_operations(role_id);

-- =========================================================
-- Users
-- app_users.id is the user ID from the external BadLogin module.
-- person.id remains an independent application identifier for any contactable actor.
-- =========================================================

-- In-organization users of app
CREATE TABLE app_users (
    id INTEGER PRIMARY KEY,
    person_id INTEGER UNIQUE REFERENCES persons(id),
    active_role_id INTEGER REFERENCES roles(id) ON DELETE SET NULL, -- role the user currently browses the app as; must be one of their assigned roles (see user_roles)
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP,
    deleted_at TIMESTAMP
);
CREATE OR REPLACE FUNCTION soft_delete_app_user(p_id INTEGER)
RETURNS VOID AS $$
BEGIN
    UPDATE app_users
    SET
        person_id = NULL,
        note = NULL,
        deleted_at = CURRENT_TIMESTAMP
    WHERE id = p_id;

    PERFORM soft_delete_bl_login(p_id); -- BadLogin module must define soft_delete_bl_login(INTEGER) in this database
END;
$$ LANGUAGE plpgsql;

-- Roles assigned to users
CREATE TABLE user_roles (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP,
    UNIQUE (user_id, role_id)
);

CREATE INDEX idx_user_roles_user_id ON user_roles(user_id);
CREATE INDEX idx_user_roles_role_id ON user_roles(role_id);


-- =========================================================
-- Currencies
-- =========================================================

-- Money can be in different currencies
CREATE TABLE currencies (
    id SERIAL PRIMARY KEY,
    code CHAR(3) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    symbol VARCHAR(10),
    decimal_places SMALLINT NOT NULL DEFAULT 2,
    deactivated_at TIMESTAMP,
    CHECK (decimal_places >= 0 AND decimal_places <= 4)
);


-- =========================================================
-- Places
-- =========================================================

-- Places in organization where Action can be located (usually rooms for example)
CREATE TABLE places (
    id SERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    abbreviation VARCHAR(30) UNIQUE,
    capacity INTEGER,
    note TEXT,
    deactivated_at TIMESTAMP,
    CHECK (capacity IS NULL OR capacity >= 0)
);

-- Users that take care of the place
CREATE TABLE place_managers (
    id SERIAL PRIMARY KEY,
    place_id INTEGER NOT NULL REFERENCES places(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP,
    UNIQUE (place_id, user_id)
);

CREATE INDEX idx_place_managers_place_id ON place_managers(place_id);
CREATE INDEX idx_place_managers_user_id ON place_managers(user_id);


-- =========================================================
-- Dictionaries for actions
-- =========================================================

-- cultural, educational, social, etc.
CREATE TABLE action_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    abbreviation VARCHAR(30) UNIQUE,
    deactivated_at TIMESTAMP
);

-- subject matter of the action, e.g. "Nature", "History" — distinct from action_types
CREATE TABLE action_topics (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    abbreviation VARCHAR(30) UNIQUE,
    deactivated_at TIMESTAMP
);

-- how the action is carried out, e.g. "Online", "Prezenčně", "Hybridně"
CREATE TABLE action_formats (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    abbreviation VARCHAR(30) UNIQUE,
    deactivated_at TIMESTAMP
);

-- draft, not agreed, confirmed, completed, cancelled, closed, etc.
CREATE TABLE action_statuses (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    code VARCHAR(100) NOT NULL UNIQUE,
    deactivated_at TIMESTAMP
);

-- pay, non-profit, free, internal, etc.
CREATE TABLE visitor_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    deactivated_at TIMESTAMP
);

-- target groups, e.g. children, seniors, etc.
CREATE TABLE visitor_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    deactivated_at TIMESTAMP
);

-- types of income for action, e.g. subsidy, gift, tickets etc.
CREATE TABLE income_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    deactivated_at TIMESTAMP
);

-- subtypes of income types, e.g. web, cash, invoice for tickets
CREATE TABLE income_subtypes (
    id SERIAL PRIMARY KEY,
    income_type_id INTEGER NOT NULL REFERENCES income_types(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    deactivated_at TIMESTAMP,
    UNIQUE (income_type_id, name)
);

-- cost types, e.g. DPP, invoice, material, service, etc.
CREATE TABLE cost_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    deactivated_at TIMESTAMP
);

-- what the cost was for, e.g. food, lighting, promotion — independent of cost_types, not a child of it
CREATE TABLE cost_objects (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    deactivated_at TIMESTAMP
);


-- =========================================================
-- Donors
-- Donor is linked to the general person table.
-- =========================================================

CREATE TABLE donors (
    person_id INTEGER PRIMARY KEY REFERENCES persons(id),
    note TEXT,
    deactivated_at TIMESTAMP
);


-- =========================================================
-- Events
-- Event is a parent object. It can contain one or more actions.
-- =========================================================

CREATE TABLE events (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- Actions
-- Every action belongs to exactly one event.
-- =========================================================

-- Main table that hold information about realization
CREATE TABLE actions (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,

    name VARCHAR(255) NOT NULL,

    staff_start_at TIMESTAMP,
    staff_end_at TIMESTAMP,

    public_start_at TIMESTAMP,
    public_end_at TIMESTAMP,

    capacity INTEGER,

    note_type TEXT,
    note TEXT,

    photograph BOOLEAN NOT NULL DEFAULT FALSE, -- whether a media/photo team should be present at the action
    photograph_note TEXT,                      -- note for the media/photo team

    not_main_organizer BOOLEAN NOT NULL DEFAULT FALSE, -- TRUE when the library is NOT the action's main organizer

    actual_status INTEGER NOT NULL REFERENCES action_statuses(id) ON DELETE RESTRICT, -- this is redundant in action_status_history, but via actual status will be actions filtered very often (for example canceled actions, open actions etc.) and in action_status_history is needed find given status, but also find wether there are not subsequent/later status on same action. This operation could be quite computationally consuming. Can be somehow fast be find largest value of created_at for each action if action_id is indexed. For now is resolved via trigger for INSERT on action_status_history and is synchronized automatically in database

    CHECK (capacity IS NULL OR capacity >= 0),
    CHECK (staff_end_at IS NULL OR staff_start_at IS NULL OR staff_end_at >= staff_start_at),
    CHECK (public_end_at IS NULL OR public_start_at IS NULL OR public_end_at >= public_start_at)
);

CREATE INDEX idx_actions_event_id ON actions(event_id);
CREATE INDEX idx_actions_public_start_at ON actions(public_start_at);

-- =========================================================
-- Action many-to-many relations
-- =========================================================

-- what are types of action
CREATE TABLE action_action_types (
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    action_type_id INTEGER NOT NULL REFERENCES action_types(id) ON DELETE RESTRICT,
    PRIMARY KEY (action_id, action_type_id)
);
CREATE INDEX idx_action_action_types_action_id ON action_action_types(action_id);
CREATE INDEX idx_action_action_types_action_type_id ON action_action_types(action_type_id);

-- what are topics of action
CREATE TABLE action_action_topics (
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    action_topic_id INTEGER NOT NULL REFERENCES action_topics(id) ON DELETE RESTRICT,
    PRIMARY KEY (action_id, action_topic_id)
);
CREATE INDEX idx_action_action_topics_action_id ON action_action_topics(action_id);
CREATE INDEX idx_action_action_topics_action_topic_id ON action_action_topics(action_topic_id);

-- how each action is carried out
CREATE TABLE action_action_formats (
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    action_format_id INTEGER NOT NULL REFERENCES action_formats(id) ON DELETE RESTRICT,
    PRIMARY KEY (action_id, action_format_id)
);
CREATE INDEX idx_action_action_formats_action_id ON action_action_formats(action_id);
CREATE INDEX idx_action_action_formats_action_format_id ON action_action_formats(action_format_id);

-- where may be action (there will be queue of actions if multiple actions in same time and place)
CREATE TABLE action_places_desire (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    place_id INTEGER NOT NULL REFERENCES places(id) ON DELETE RESTRICT,
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    first_at_queue_at TIMESTAMP,    -- when this request reached 1st place in the room's queue (NULL = not first); maintained by PlaceQueueService::reconcile()
    last_notification_at TIMESTAMP, -- when a queue notification was last created for this request
    UNIQUE (action_id, place_id)
);
CREATE INDEX idx_action_places_desire_action_id ON action_places_desire(action_id);
CREATE INDEX idx_action_places_desire_place_id ON action_places_desire(place_id);

-- where is action assigned
CREATE TABLE action_places (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    place_id INTEGER NOT NULL REFERENCES places(id) ON DELETE RESTRICT,
    note TEXT,
    UNIQUE (action_id, place_id)
);
CREATE INDEX idx_action_places_action_id ON action_places(action_id);
CREATE INDEX idx_action_places_place_id ON action_places(place_id);

-- notes of action progress
CREATE TABLE action_status_history (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    action_status_id INTEGER NOT NULL REFERENCES action_statuses(id) ON DELETE RESTRICT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INTEGER REFERENCES app_users(id),
    note TEXT
);
CREATE INDEX idx_action_status_history_action_id ON action_status_history(action_id);

CREATE OR REPLACE FUNCTION sync_action_actual_status()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE actions
    SET actual_status = NEW.action_status_id
    WHERE id = NEW.action_id;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_sync_action_actual_status
AFTER INSERT ON action_status_history
FOR EACH ROW EXECUTE FUNCTION sync_action_actual_status();

-- for who the action is
CREATE TABLE action_target_visitor_categories (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    visitor_category_id INTEGER NOT NULL REFERENCES visitor_categories(id) ON DELETE RESTRICT,
    UNIQUE (action_id, visitor_category_id)
);
CREATE INDEX idx_action_target_categories_action_id ON action_target_visitor_categories(action_id);


-- =========================================================
-- Attendance
-- =========================================================

-- who/how many was on action as visitors
CREATE TABLE action_attendance (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    visitor_type_id INTEGER NOT NULL REFERENCES visitor_types(id),
    visitor_category_id INTEGER NOT NULL REFERENCES visitor_categories(id),
    visitor_count INTEGER NOT NULL,
    note TEXT,
    UNIQUE (action_id, visitor_type_id, visitor_category_id),
    CHECK (visitor_count >= 0)
);

CREATE INDEX idx_action_attendance_action_id ON action_attendance(action_id);


-- =========================================================
-- Action finances - incomes
-- =========================================================

-- what are incomes of action
CREATE TABLE action_incomes (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    income_type_id INTEGER NOT NULL REFERENCES income_types(id) ON DELETE RESTRICT,
    income_subtype_id INTEGER REFERENCES income_subtypes(id) ON DELETE RESTRICT,
    donor_id INTEGER REFERENCES donors(person_id) ON DELETE RESTRICT, 
    amount NUMERIC(12, 2) NOT NULL,
    currency_id INTEGER NOT NULL REFERENCES currencies(id) ON DELETE RESTRICT,
    reference_number VARCHAR(100),
    note TEXT,
    CHECK (amount >= 0)
);
CREATE INDEX idx_action_incomes_action_id ON action_incomes(action_id);

CREATE OR REPLACE FUNCTION check_income_subtype_matches_type()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.income_subtype_id IS NOT NULL THEN
        IF NOT EXISTS (
            SELECT 1 FROM income_subtypes
            WHERE id = NEW.income_subtype_id
              AND income_type_id = NEW.income_type_id
        ) THEN
            RAISE EXCEPTION 'income subtype "%" (id=%) does not belong to income type "%" (id=%)',
                (SELECT name FROM income_subtypes WHERE id = NEW.income_subtype_id),
                NEW.income_subtype_id,
                (SELECT name FROM income_types    WHERE id = NEW.income_type_id),
                NEW.income_type_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_action_incomes_subtype_type
BEFORE INSERT OR UPDATE ON action_incomes
FOR EACH ROW EXECUTE FUNCTION check_income_subtype_matches_type();


-- =========================================================
-- Action finances - costs
-- =========================================================

CREATE TABLE action_costs (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    cost_type_id INTEGER NOT NULL REFERENCES cost_types(id) ON DELETE RESTRICT,
    cost_object_id INTEGER NOT NULL REFERENCES cost_objects(id) ON DELETE RESTRICT,
    amount NUMERIC(12, 2) NOT NULL,
    currency_id INTEGER NOT NULL REFERENCES currencies(id) ON DELETE RESTRICT,
    reference_number VARCHAR(100),
    note TEXT,
    CHECK (amount >= 0)
);

CREATE INDEX idx_action_costs_action_id ON action_costs(action_id);

-- =========================================================
-- Action staff relations
-- =========================================================

-- who is responsible for action
CREATE TABLE action_organizers (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES app_users(id) ON DELETE RESTRICT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP
);

-- who know about action and can substitute organizer
CREATE TABLE action_substitutes (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES app_users(id) ON DELETE RESTRICT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP 
);

-- who is related to action as worker
CREATE TABLE action_staff (
    id SERIAL PRIMARY KEY,
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    person_id INTEGER NOT NULL REFERENCES persons(id) ON DELETE RESTRICT,
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at TIMESTAMP,
    UNIQUE (action_id, person_id)
);

CREATE INDEX idx_action_organizers_action_id ON action_organizers(action_id);
CREATE INDEX idx_action_organizers_user_id ON action_organizers(user_id);
CREATE INDEX idx_action_substitutes_action_id ON action_substitutes(action_id);
CREATE INDEX idx_action_substitutes_user_id ON action_substitutes(user_id);
CREATE INDEX idx_action_staff_action_id ON action_staff(action_id);
CREATE INDEX idx_action_staff_person_id ON action_staff(person_id);


-- =========================================================
-- Notifications
-- =========================================================

CREATE TABLE notifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP,
    text TEXT NOT NULL,
    email_sent_at TIMESTAMP,
    email_failed_at TIMESTAMP, -- last failed email hand-off; cron/daily.php retries these for 2 days
    requires_approval BOOLEAN NOT NULL DEFAULT FALSE,
    approved_at TIMESTAMP,
    related_action_id INTEGER REFERENCES actions(id) ON DELETE SET NULL
);

CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_created_at ON notifications(created_at);
CREATE INDEX idx_notifications_related_action_id ON notifications(related_action_id);

-- =========================================================
-- Reminders that a still-draft ("Rozjednaná") action is approaching its start (30 / 7 days ahead).
-- One row per already-sent reminder; start_date is part of the key so a postponed action gets
-- reminded again for its new date. See NotificationService::sendDraftStartReminders().
-- =========================================================
CREATE TABLE IF NOT EXISTS action_start_reminders (
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    start_date DATE NOT NULL,
    days_before INTEGER NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (action_id, start_date, days_before)
);

-- =========================================================
-- One-off reminders about an action (cron/daily.php + dashboard load), one row per sent alert:
--   overdue  — Rozjednaná/Potvrzená action whose public end passed (ref_date = end date)
--   no_room  — action starting soon with no confirmed room       (ref_date = start date)
-- ref_date is part of the key, so a rescheduled action gets reminded again for its new date.
-- See NotificationService::sendOverdueActionReminders() / sendMissingRoomReminders().
-- =========================================================
CREATE TABLE IF NOT EXISTS action_alerts (
    action_id INTEGER NOT NULL REFERENCES actions(id) ON DELETE CASCADE,
    alert_code VARCHAR(30) NOT NULL,
    ref_date DATE NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (action_id, alert_code, ref_date)
);


-- =========================================================
-- Audit log
-- =========================================================

CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES app_users(id) ON DELETE RESTRICT,
    entity_type VARCHAR(100) NOT NULL,
    entity_id INTEGER NOT NULL,
    operation VARCHAR(100) NOT NULL,
    old_data JSONB,
    new_data JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_entity ON audit_logs(entity_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at);

-- =========================================================
-- Building code duty (kdo daný den koduje/odkoduje budovu)
-- =========================================================
-- Only ever needs to hold roughly 2 months back and 2 months forward of data (per app spec) — kept
-- from growing unbounded by cleanup_old_building_code_duty(), which the app calls opportunistically
-- (every time this table is read/written, see BuildingCodeDutyRepository) instead of relying on a
-- separate cron job, since this table is tiny and the DELETE is cheap even run that often.

CREATE TABLE building_code_duty (
    id SERIAL PRIMARY KEY,
    duty_date DATE NOT NULL UNIQUE,
    primary_user_id INTEGER REFERENCES app_users(id) ON DELETE SET NULL,
    substitute_user_id INTEGER REFERENCES app_users(id) ON DELETE SET NULL,
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_building_code_duty_date ON building_code_duty(duty_date);

CREATE OR REPLACE FUNCTION cleanup_old_building_code_duty()
RETURNS VOID AS $$
BEGIN
    DELETE FROM building_code_duty WHERE duty_date < (CURRENT_DATE - INTERVAL '2 months');
END;
$$ LANGUAGE plpgsql;

-- =========================================================
-- Helpful views
-- =========================================================
COMMIT;   