-- =============================================================
-- Showroom data: sample people, rooms, events and actions for demonstrating the app.
-- Load AFTER database/setup.sql (create.sql + seed.sql), into a demo database only:
--
--   psql -U postgres -d event_manager_demo -f database/seed_showroom.sql
--
-- Log in as admin@ad.min / admin (Administrátor + Organizátor, switchable in the header).
-- The other sample accounts (…@knihovna.example) all have the password "demo".
--
-- All dates are relative to the day the file is loaded (past actions are already over and
-- waiting to be completed/closed, future ones are being planned), so the demo always looks current.
-- Loading it twice is refused — see the guard below.
-- =============================================================

BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM bl_login WHERE email = 'admin@ad.min') THEN
        RAISE EXCEPTION 'Showroom data is already loaded (admin@ad.min exists).';
    END IF;
END;
$$;

-- -------------------------------------------------------------
-- Dictionaries the sample actions (and the Statistika export) use, on top of seed.sql
-- -------------------------------------------------------------

INSERT INTO action_types (name, abbreviation) VALUES
    ('Výstava', 'výst');

INSERT INTO action_topics (name, abbreviation) VALUES
    ('Literatura', 'lit'),
    ('Historie', 'hist'),
    ('Informační technologie', 'ICT'),
    ('Jazyky', 'jaz'),
    ('Hry', 'hry'),
    ('Fotografie', 'foto');

INSERT INTO action_formats (name, abbreviation) VALUES
    ('Prezenčně', 'prez'),
    ('Online', 'online'),
    ('Hybridně', 'hybr');

INSERT INTO visitor_categories (name) VALUES
    ('Žáci MŠ'),
    ('Žáci 1. st. ZŠ'),
    ('Žáci 2. st. ZŠ'),
    ('Žáci SŠ');

INSERT INTO cost_objects (name) VALUES
    ('Honorář'),
    ('Propagace'),
    ('Občerstvení'),
    ('Materiál na tvoření');

-- -------------------------------------------------------------
-- Rooms
-- -------------------------------------------------------------

INSERT INTO places (name, abbreviation, capacity, note) VALUES
    ('Sál, 1. patro',      'SÁL', 80, 'Klíč na vrátnici, ozvučení a projektor v kabinetu za pódiem.'),
    ('Velká lektorna',     'VL',  30, NULL),
    ('Malá lektorna',      'ML',  15, NULL),
    ('Zasedací místnost',  'ZM',  12, 'Interaktivní tabule – ovladač u správce budovy.'),
    ('Dětská čítárna',     'DČ',  25, 'Po akci vrátit sedací vaky do regálu.'),
    ('Fotokomora',         'FK',   6, 'Pouze pro proškolené – chemie v uzamčené skříni.'),
    ('Galerie v přízemí',  'GAL', NULL, 'Výstavní panely jsou ve skladu v suterénu.');

-- -------------------------------------------------------------
-- People: library staff with accounts, plus guests/partners without one
-- -------------------------------------------------------------

INSERT INTO persons (full_name, short_name, note) VALUES
    ('Admin Ukázkový',               'ADM', 'Ukázkový účet správce aplikace.'),
    ('Kateřina Šimková',             'KŠ',  'Lekce pro školy, besedy.'),
    ('Hana Marková',                 'HM',  'Kurzy jazyků a univerzita třetího věku.'),
    ('Václav Horák',                 'VH',  'Hudební a komunitní akce, správce sálu.'),
    ('Jitka Tomanová',               'JT',  'Zastupuje organizátory besed.'),
    ('Daniel Beneš',                 'DB',  'Vedení knihovny – jen nahlíží.'),
    ('Pavel Kříž',                   'PK',  'Technická výpomoc na akcích.'),
    ('Ivana Svobodová',              NULL,  'Novinářka a reportérka, host besed.'),
    ('Tomáš Vrána',                  NULL,  'Externí zvukař, fakturuje.'),
    ('Spolek Romano Drom',           NULL,  'Pořadatel Festivalu romského jazyka.'),
    ('Nadace Knihovna pro všechny',  NULL,  'Podporuje akce pro děti.');

INSERT INTO contacts (person_id, contact_type_id, value, note)
SELECT p.id, ct.id, c.value, c.note
FROM (VALUES
    ('Kateřina Šimková',            'tel',   '+420 601 111 222', NULL),
    ('Kateřina Šimková',            'email', 'katerina.simkova@knihovna.example', NULL),
    ('Hana Marková',                'tel',   '+420 602 333 444', 'Nejlépe odpoledne'),
    ('Václav Horák',                'tel',   '+420 603 555 666', NULL),
    ('Ivana Svobodová',             'email', 'ivana.svobodova@redakce.example', 'Domlouvat přes asistentku'),
    ('Ivana Svobodová',             'tel',   '+420 777 123 456', NULL),
    ('Tomáš Vrána',                 'tel',   '+420 724 987 654', NULL),
    ('Spolek Romano Drom',          'email', 'info@romanodrom.example', NULL),
    ('Nadace Knihovna pro všechny', 'email', 'granty@nadace.example', 'Grantové výzvy vždy v lednu')
) AS c(person, type, value, note)
JOIN persons p ON p.full_name = c.person
JOIN contact_types ct ON ct.abbreviation = c.type;

INSERT INTO donors (person_id, note)
SELECT id, 'Roční příspěvek na akce pro děti.' FROM persons WHERE full_name = 'Nadace Knihovna pro všechny';

-- Accounts (BadLogin): admin@ad.min / admin, everyone else / demo (bcrypt hashes of those passwords).
INSERT INTO bl_login (email, password_hash, is_email_verified) VALUES
    ('admin@ad.min',                       '$2y$10$TJ0OXH93OCGTRsR8Pcm2DefF.26sF6XDPgNo8Eg3nmoS4MSQsPGva', TRUE),
    ('katerina.simkova@knihovna.example',  '$2y$10$xAUcDIHjXZhwGbJ.UrTDs.Hd6gd3pd/EV1aKy5BtDWaoWDshDx2Sa', TRUE),
    ('hana.markova@knihovna.example',      '$2y$10$xAUcDIHjXZhwGbJ.UrTDs.Hd6gd3pd/EV1aKy5BtDWaoWDshDx2Sa', TRUE),
    ('vaclav.horak@knihovna.example',      '$2y$10$xAUcDIHjXZhwGbJ.UrTDs.Hd6gd3pd/EV1aKy5BtDWaoWDshDx2Sa', TRUE),
    ('jitka.tomanova@knihovna.example',    '$2y$10$xAUcDIHjXZhwGbJ.UrTDs.Hd6gd3pd/EV1aKy5BtDWaoWDshDx2Sa', TRUE),
    ('daniel.benes@knihovna.example',      '$2y$10$xAUcDIHjXZhwGbJ.UrTDs.Hd6gd3pd/EV1aKy5BtDWaoWDshDx2Sa', TRUE),
    ('pavel.kriz@knihovna.example',        '$2y$10$xAUcDIHjXZhwGbJ.UrTDs.Hd6gd3pd/EV1aKy5BtDWaoWDshDx2Sa', TRUE);

-- app_users.id is the BadLogin id; roles are listed active role first.
CREATE TEMPORARY TABLE showroom_accounts (email TEXT, short_name TEXT, roles TEXT[]) ON COMMIT DROP;
INSERT INTO showroom_accounts VALUES
    ('admin@ad.min',                      'ADM', ARRAY['administrator', 'organizer']),
    ('katerina.simkova@knihovna.example', 'KŠ',  ARRAY['organizer']),
    ('hana.markova@knihovna.example',     'HM',  ARRAY['organizer']),
    ('vaclav.horak@knihovna.example',     'VH',  ARRAY['organizer', 'place_manager']),
    ('jitka.tomanova@knihovna.example',   'JT',  ARRAY['substitute']),
    ('daniel.benes@knihovna.example',     'DB',  ARRAY['viewer']),
    ('pavel.kriz@knihovna.example',       'PK',  ARRAY['stuff']);

INSERT INTO app_users (id, person_id, active_role_id)
SELECT l.id, p.id, r.id
FROM showroom_accounts a
JOIN bl_login l ON l.email = a.email
JOIN persons p ON p.short_name = a.short_name
JOIN roles r ON r.code = a.roles[1];

INSERT INTO user_roles (user_id, role_id)
SELECT l.id, r.id
FROM showroom_accounts a
JOIN bl_login l ON l.email = a.email
CROSS JOIN LATERAL unnest(a.roles) AS role_code
JOIN roles r ON r.code = role_code;

INSERT INTO place_managers (place_id, user_id)
SELECT pl.id, u.id
FROM places pl, app_users u JOIN persons p ON p.id = u.person_id
WHERE pl.abbreviation = 'SÁL' AND p.short_name = 'VH';

-- -------------------------------------------------------------
-- Helpers for building actions (temporary — gone when the session ends)
-- -------------------------------------------------------------

-- Day offset from today + time of day.
CREATE FUNCTION pg_temp.day_at(p_days INTEGER, p_time TIME) RETURNS TIMESTAMP AS $fn$
    SELECT (CURRENT_DATE + p_days) + p_time;
$fn$ LANGUAGE sql;

CREATE FUNCTION pg_temp.user_id(p_short_name TEXT) RETURNS INTEGER AS $fn$
    SELECT u.id FROM app_users u JOIN persons p ON p.id = u.person_id WHERE p.short_name = p_short_name;
$fn$ LANGUAGE sql;

/*
 * One action with its event (created on first use), staff window ±30 min around the public one,
 * dictionaries, organizers/substitutes/helpers and the status history walked through p_statuses
 * (e.g. {draft,confirmed,completed}) with plausible timestamps; the history trigger keeps
 * actions.actual_status in sync. Returns the action id.
 */
CREATE FUNCTION pg_temp.demo_action(
    p_event TEXT, p_name TEXT, p_start TIMESTAMP, p_end TIMESTAMP, p_statuses TEXT[],
    p_organizers TEXT[], p_types TEXT[], p_topics TEXT[] DEFAULT '{}', p_formats TEXT[] DEFAULT '{Prezenčně}',
    p_categories TEXT[] DEFAULT '{}', p_capacity INTEGER DEFAULT NULL, p_note TEXT DEFAULT NULL,
    p_photograph BOOLEAN DEFAULT FALSE, p_photograph_note TEXT DEFAULT NULL, p_not_main BOOLEAN DEFAULT FALSE,
    p_substitutes TEXT[] DEFAULT '{}', p_staff TEXT[] DEFAULT '{}'
) RETURNS INTEGER AS $fn$
DECLARE
    v_event   INTEGER;
    v_action  INTEGER;
    v_by      INTEGER := pg_temp.user_id(p_organizers[1]);
    v_created TIMESTAMP := LEAST(p_start - INTERVAL '30 days', LOCALTIMESTAMP - INTERVAL '10 days');
    v_status  TEXT;
    v_at      TIMESTAMP;
BEGIN
    SELECT id INTO v_event FROM events WHERE name = p_event;
    IF v_event IS NULL THEN
        INSERT INTO events (name, created_at) VALUES (p_event, v_created) RETURNING id INTO v_event;
    END IF;

    INSERT INTO actions (event_id, name, staff_start_at, staff_end_at, public_start_at, public_end_at, capacity, note,
                         photograph, photograph_note, not_main_organizer, actual_status)
    VALUES (v_event, p_name, p_start - INTERVAL '30 minutes', p_end + INTERVAL '30 minutes', p_start, p_end, p_capacity, p_note,
            p_photograph, p_photograph_note, p_not_main, (SELECT id FROM action_statuses WHERE code = 'draft'))
    RETURNING id INTO v_action;

    FOREACH v_status IN ARRAY p_statuses LOOP
        v_at := CASE v_status
            WHEN 'draft'     THEN v_created
            WHEN 'confirmed' THEN v_created + INTERVAL '3 days'
            WHEN 'completed' THEN p_end + INTERVAL '2 hours'
            WHEN 'closed'    THEN p_end + INTERVAL '2 days'
            ELSE                  v_created + INTERVAL '5 days'   -- cancelled / not_agreed
        END;
        INSERT INTO action_status_history (action_id, action_status_id, created_at, created_by_user_id, note)
        VALUES (v_action, (SELECT id FROM action_statuses WHERE code = v_status), v_at, v_by,
                CASE v_status WHEN 'cancelled' THEN 'Lektor onemocněl, náhradní termín zatím není.'
                              WHEN 'not_agreed' THEN 'Nepodařilo se domluvit honorář.' END);
    END LOOP;

    INSERT INTO action_action_types (action_id, action_type_id)
        SELECT v_action, id FROM action_types WHERE name = ANY (p_types);
    INSERT INTO action_action_topics (action_id, action_topic_id)
        SELECT v_action, id FROM action_topics WHERE name = ANY (p_topics);
    INSERT INTO action_action_formats (action_id, action_format_id)
        SELECT v_action, id FROM action_formats WHERE name = ANY (p_formats);
    INSERT INTO action_target_visitor_categories (action_id, visitor_category_id)
        SELECT v_action, id FROM visitor_categories WHERE name = ANY (p_categories);
    INSERT INTO action_organizers (action_id, user_id, created_at)
        SELECT v_action, pg_temp.user_id(s), v_created FROM unnest(p_organizers) AS s;
    INSERT INTO action_substitutes (action_id, user_id, created_at)
        SELECT v_action, pg_temp.user_id(s), v_created FROM unnest(p_substitutes) AS s;
    INSERT INTO action_staff (action_id, person_id, created_at)
        SELECT v_action, p.id, v_created FROM persons p WHERE p.full_name = ANY (p_staff);

    RETURN v_action;
END;
$fn$ LANGUAGE plpgsql;

-- A room request; p_confirmed also assigns the room (as the app does: request + assignment).
-- p_requested_at decides the action's place in that room's queue (default: when the action was created).
CREATE FUNCTION pg_temp.demo_room(p_action TEXT, p_room TEXT, p_confirmed BOOLEAN, p_note TEXT DEFAULT NULL,
                                  p_requested_at TIMESTAMP DEFAULT NULL) RETURNS VOID AS $fn$
DECLARE
    v_action INTEGER := (SELECT id FROM actions WHERE name = p_action);
    v_place  INTEGER := (SELECT id FROM places WHERE abbreviation = p_room);
BEGIN
    INSERT INTO action_places_desire (action_id, place_id, note, created_at)
    VALUES (v_action, v_place, p_note,
            COALESCE(p_requested_at, (SELECT MIN(created_at) FROM action_status_history WHERE action_id = v_action) + INTERVAL '1 hour'));
    IF p_confirmed THEN
        INSERT INTO action_places (action_id, place_id, note) VALUES (v_action, v_place, p_note);
    END IF;
END;
$fn$ LANGUAGE plpgsql;

CREATE FUNCTION pg_temp.demo_attendance(p_action TEXT, p_type TEXT, p_category TEXT, p_count INTEGER, p_note TEXT DEFAULT NULL) RETURNS VOID AS $fn$
    INSERT INTO action_attendance (action_id, visitor_type_id, visitor_category_id, visitor_count, note)
    VALUES ((SELECT id FROM actions WHERE name = p_action),
            (SELECT id FROM visitor_types WHERE name = p_type),
            (SELECT id FROM visitor_categories WHERE name = p_category),
            p_count, p_note);
$fn$ LANGUAGE sql;

CREATE FUNCTION pg_temp.demo_income(p_action TEXT, p_type TEXT, p_subtype TEXT, p_amount NUMERIC, p_currency TEXT,
                                    p_reference TEXT DEFAULT NULL, p_note TEXT DEFAULT NULL, p_donor TEXT DEFAULT NULL) RETURNS VOID AS $fn$
    INSERT INTO action_incomes (action_id, income_type_id, income_subtype_id, donor_id, amount, currency_id, reference_number, note)
    VALUES ((SELECT id FROM actions WHERE name = p_action),
            (SELECT id FROM income_types WHERE name = p_type),
            (SELECT s.id FROM income_subtypes s JOIN income_types t ON t.id = s.income_type_id WHERE t.name = p_type AND s.name = p_subtype),
            (SELECT d.person_id FROM donors d JOIN persons p ON p.id = d.person_id WHERE p.full_name = p_donor),
            p_amount, (SELECT id FROM currencies WHERE code = p_currency), p_reference, p_note);
$fn$ LANGUAGE sql;

CREATE FUNCTION pg_temp.demo_cost(p_action TEXT, p_type TEXT, p_object TEXT, p_amount NUMERIC, p_currency TEXT,
                                  p_reference TEXT DEFAULT NULL, p_note TEXT DEFAULT NULL) RETURNS VOID AS $fn$
    INSERT INTO action_costs (action_id, cost_type_id, cost_object_id, amount, currency_id, reference_number, note)
    VALUES ((SELECT id FROM actions WHERE name = p_action),
            (SELECT id FROM cost_types WHERE name = p_type),
            (SELECT id FROM cost_objects WHERE name = p_object),
            p_amount, (SELECT id FROM currencies WHERE code = p_currency), p_reference, p_note);
$fn$ LANGUAGE sql;

-- -------------------------------------------------------------
-- Past actions — over, in various stages of being wrapped up
-- -------------------------------------------------------------

-- Uzavřená: everything filled in — attendance, income incl. a donor's gift, costs.
SELECT pg_temp.demo_action('Knihovnické lekce pro školy', 'Poznej knihovnu jinak', pg_temp.day_at(-21, '09:30'), pg_temp.day_at(-21, '11:00'),
    '{draft,confirmed,completed,closed}', '{KŠ,HM}', '{Vzdělávací}', '{Literatura}', '{Prezenčně}', '{"Žáci 1. st. ZŠ"}', 30,
    'Třída 3.B ZŠ Amálská, paní učitelka Nováková.');
SELECT pg_temp.demo_room('Poznej knihovnu jinak', 'SÁL', TRUE, 'Židle do půlkruhu, 30 ks.');
SELECT pg_temp.demo_attendance('Poznej knihovnu jinak', 'Neplatící', 'Žáci 1. st. ZŠ', 24);
SELECT pg_temp.demo_attendance('Poznej knihovnu jinak', 'Neplatící', 'Dospělí', 2, 'Doprovod – učitelky');
SELECT pg_temp.demo_income('Poznej knihovnu jinak', 'Dar', NULL, 5000, 'CZK', 'DAR-2026-014', 'Příspěvek na lekce pro školy', 'Nadace Knihovna pro všechny');
SELECT pg_temp.demo_cost('Poznej knihovnu jinak', 'Materiál', 'Materiál na tvoření', 350, 'CZK', 'P-118', 'Pastelky, papíry');

-- Uzavřená: paid talk with a guest — Vstupné (cash + web), honorarium, promotion, media team.
SELECT pg_temp.demo_action('Beseda s osobností', 'BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', pg_temp.day_at(-14, '18:00'), pg_temp.day_at(-14, '19:30'),
    '{draft,confirmed,completed,closed}', '{KŠ}', '{Kulturní}', '{Literatura}', '{Prezenčně}', '{Dospělí,Senioři}', 60, NULL,
    TRUE, 'Fotit jen úvod a závěr, během čtení si host fotky nepřeje.', FALSE, '{JT}', '{"Tomáš Vrána","Pavel Kříž"}');
SELECT pg_temp.demo_room('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'VL', TRUE, 'Stolek a dvě křesla pro hosta a moderátorku.');
SELECT pg_temp.demo_attendance('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'Platící', 'Dospělí', 35);
SELECT pg_temp.demo_attendance('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'Platící', 'Senioři', 12);
SELECT pg_temp.demo_attendance('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'Neplatící', 'Dospělí', 3, 'Čestné vstupenky');
SELECT pg_temp.demo_income('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'Vstupné', 'Hotovost', 2900, 'CZK', 'PPD-0412');
SELECT pg_temp.demo_income('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'Vstupné', 'Web', 1800, 'CZK', 'GOPAY-7781');
SELECT pg_temp.demo_cost('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'DPP', 'Honorář', 3000, 'CZK', 'DPP-2026-31', 'Ivana Svobodová');
SELECT pg_temp.demo_cost('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'Faktura', 'Honorář', 1200, 'CZK', 'FV-2026-208', 'Zvuk – Tomáš Vrána');
SELECT pg_temp.demo_cost('BSB – Ivana Svobodová, beseda s novinářkou a reportérkou', 'Faktura', 'Propagace', 800, 'CZK', 'FV-2026-197', 'Plakáty A3');

-- Proběhlá with attendance but no economic data yet → "Uzavřít akci" is refused (shows the highlighted section).
SELECT pg_temp.demo_action('Digitální gramotnost', 'Digitální služby státu v knihovně', pg_temp.day_at(-10, '10:00'), pg_temp.day_at(-10, '12:00'),
    '{draft,confirmed,completed}', '{HM}', '{Vzdělávací}', '{"Informační technologie"}', '{Hybridně}', '{Senioři,Dospělí}', 12);
SELECT pg_temp.demo_room('Digitální služby státu v knihovně', 'ZM', TRUE, 'Připravit 6 notebooků.');
SELECT pg_temp.demo_attendance('Digitální služby státu v knihovně', 'Neplatící', 'Senioři', 9);
SELECT pg_temp.demo_attendance('Digitální služby státu v knihovně', 'Neplatící', 'Dospělí', 2, 'Online připojení');

-- Proběhlá online action for children.
SELECT pg_temp.demo_action('Kamishibai', 'Kamishibai on-line s Kubou a Sofií', pg_temp.day_at(-7, '16:30'), pg_temp.day_at(-7, '17:30'),
    '{draft,confirmed,completed}', '{VH}', '{Kulturní}', '{Literatura}', '{Online}', '{Děti}', NULL,
    'Vysíláno z dětské čítárny, záznam na YouTube.');
SELECT pg_temp.demo_room('Kamishibai on-line s Kubou a Sofií', 'DČ', TRUE);
SELECT pg_temp.demo_attendance('Kamishibai on-line s Kubou a Sofií', 'Neplatící', 'Děti', 41, 'Počet připojení');

-- Still Potvrzená although it's over and has no attendance → overdue reminder; "Označit jako proběhlou" is refused.
SELECT pg_temp.demo_action('Festival romského jazyka', 'Festival romského jazyka – Jan Cina', pg_temp.day_at(-5, '18:00'), pg_temp.day_at(-5, '20:00'),
    '{draft,confirmed}', '{VH,KŠ}', '{Kulturní,Sociální}', '{Jazyky}', '{Prezenčně}', '{Dospělí,Mládež}', 80, NULL,
    TRUE, 'Hlavní pořadatel má vlastního fotografa, stačí pár snímků sálu.', TRUE);
SELECT pg_temp.demo_room('Festival romského jazyka – Jan Cina', 'SÁL', TRUE, 'Pódium a dva mikrofony.');

-- Zrušená (rooms released, as the app does on cancelling).
SELECT pg_temp.demo_action('Šachový klub', 'Šachový turnaj pro rodiče s dětmi', pg_temp.day_at(-3, '15:00'), pg_temp.day_at(-3, '18:00'),
    '{draft,confirmed,cancelled}', '{VH}', '{Sociální}', '{Hry}', '{Prezenčně}', '{Děti,Dospělí}', 24);

-- -------------------------------------------------------------
-- Upcoming actions — being planned
-- -------------------------------------------------------------

-- Multi-day exhibition (spans several days of the weekly plan).
SELECT pg_temp.demo_action('Kladno na starých fotografiích', 'Výstava: Kladno na starých fotografiích', pg_temp.day_at(1, '09:00'), pg_temp.day_at(20, '18:00'),
    '{draft,confirmed}', '{VH,KŠ}', '{Výstava}', '{Historie,Fotografie}', '{Prezenčně}', '{}', NULL,
    'Vernisáž první den v 17:00.', TRUE, 'Vernisáž – fotit hosty i panely.');
SELECT pg_temp.demo_room('Výstava: Kladno na starých fotografiích', 'GAL', TRUE, 'Instalace den předem odpoledne.');

-- A course with several lessons in one event; paid in advance.
SELECT pg_temp.demo_action('Kurz italštiny', 'Italština pro začátečníky – lekce 5', pg_temp.day_at(2, '14:30'), pg_temp.day_at(2, '16:30'),
    '{draft,confirmed}', '{HM}', '{Vzdělávací}', '{Jazyky}', '{Prezenčně}', '{Dospělí,Senioři}', 12);
SELECT pg_temp.demo_room('Italština pro začátečníky – lekce 5', 'ZM', TRUE);
SELECT pg_temp.demo_income('Italština pro začátečníky – lekce 5', 'Vstupné', 'Web', 1500, 'CZK', 'GOPAY-7840', 'Kurzovné za 10 lekcí (10 × 150 Kč)');
SELECT pg_temp.demo_action('Kurz italštiny', 'Italština pro začátečníky – lekce 6', pg_temp.day_at(9, '14:30'), pg_temp.day_at(9, '16:30'),
    '{draft,confirmed}', '{HM}', '{Vzdělávací}', '{Jazyky}', '{Prezenčně}', '{Dospělí,Senioři}', 12);
SELECT pg_temp.demo_room('Italština pro začátečníky – lekce 6', 'ZM', TRUE);

SELECT pg_temp.demo_action('Deskoherní klub', 'Deskoherní klub', pg_temp.day_at(3, '14:30'), pg_temp.day_at(3, '16:30'),
    '{draft,confirmed}', '{VH}', '{Sociální}', '{Hry}', '{Prezenčně}', '{Mládež}', 20, NULL, FALSE, NULL, FALSE, '{JT}', '{"Pavel Kříž"}');
SELECT pg_temp.demo_room('Deskoherní klub', 'VL', TRUE);

-- Room queue: two drafts want the Sál at overlapping times — the earlier request is first in the queue.
SELECT pg_temp.demo_action('Lekce historie pro SŠ', 'Cesta k Sametu', pg_temp.day_at(4, '09:30'), pg_temp.day_at(4, '11:00'),
    '{draft}', '{KŠ}', '{Vzdělávací}', '{Historie}', '{Prezenčně}', '{"Žáci SŠ"}', 60);
SELECT pg_temp.demo_room('Cesta k Sametu', 'SÁL', FALSE, 'Projekce dobových záběrů.', LOCALTIMESTAMP - INTERVAL '6 days');
SELECT pg_temp.demo_action('Filmový klub', 'Předpremiéra dokumentu Raymond', pg_temp.day_at(4, '10:00'), pg_temp.day_at(4, '12:00'),
    '{draft}', '{VH}', '{Kulturní}', '{}', '{Prezenčně}', '{Dospělí}', 80);
SELECT pg_temp.demo_room('Předpremiéra dokumentu Raymond', 'SÁL', FALSE, NULL, LOCALTIMESTAMP - INTERVAL '2 days');
SELECT pg_temp.demo_room('Předpremiéra dokumentu Raymond', 'VL', FALSE, 'Náhradní varianta, pokud nevyjde sál.', LOCALTIMESTAMP - INTERVAL '2 days');

-- Sál already assigned; a later draft overlapping it waits in the queue behind it.
SELECT pg_temp.demo_action('Univerzita třetího věku', 'VU3V – Osobnosti české moderní architektury', pg_temp.day_at(6, '14:00'), pg_temp.day_at(6, '16:00'),
    '{draft,confirmed}', '{HM}', '{Vzdělávací}', '{Historie}', '{Prezenčně}', '{Senioři}', 40, 'Promítání přednášky z FA ČVUT, po ní diskuse.');
SELECT pg_temp.demo_room('VU3V – Osobnosti české moderní architektury', 'SÁL', TRUE, 'Projektor + plátno.');
SELECT pg_temp.demo_action('Knihovna pro duši', 'Knihovna pro duši', pg_temp.day_at(6, '15:30'), pg_temp.day_at(6, '17:30'),
    '{draft}', '{KŠ}', '{Sociální}', '{Literatura}', '{Prezenčně}', '{Senioři}', 15);
SELECT pg_temp.demo_room('Knihovna pro duši', 'SÁL', FALSE, NULL, LOCALTIMESTAMP - INTERVAL '1 day');
SELECT pg_temp.demo_room('Knihovna pro duši', 'ML', FALSE, NULL, LOCALTIMESTAMP - INTERVAL '1 day');

-- Draft starting soon without any room → missing-room and draft reminders; "Potvrdit akci" is refused.
SELECT pg_temp.demo_action('Lekce pro mateřské školy', 'Na Bachra!', pg_temp.day_at(5, '08:30'), pg_temp.day_at(5, '09:20'),
    '{draft}', '{KŠ}', '{Vzdělávací}', '{Literatura}', '{Prezenčně}', '{"Žáci MŠ"}', 25, 'MŠ Pod Hájem, 2 třídy.');

SELECT pg_temp.demo_action('Fotografické dílny', 'Kouzlo analogové fotografie', pg_temp.day_at(11, '09:30'), pg_temp.day_at(11, '11:30'),
    '{draft,confirmed}', '{VH}', '{Vzdělávací,Kulturní}', '{Fotografie}', '{Prezenčně}', '{Mládež,Dospělí}', 6, NULL,
    TRUE, 'Fotit práci v temné komoře – jen bez blesku.');
SELECT pg_temp.demo_room('Kouzlo analogové fotografie', 'FK', TRUE, 'Doplnit vývojku a ustalovač.');
SELECT pg_temp.demo_income('Kouzlo analogové fotografie', 'Vstupné', 'Faktura', 40, 'EUR', 'FV-EU-0007', 'Skupina z partnerské knihovny v Žilině');

-- Nedojednaná (no rooms kept).
SELECT pg_temp.demo_action('Dílny pro dospělé', 'Workshop ručního vázání knih', pg_temp.day_at(12, '17:00'), pg_temp.day_at(12, '19:30'),
    '{draft,not_agreed}', '{HM}', '{Kulturní}', '{Literatura}', '{Prezenčně}', '{Dospělí}', 10);

-- Further ahead, partner-organized, room just requested.
SELECT pg_temp.demo_action('Noc literatury', 'Noc literatury – čtení na neobvyklých místech', pg_temp.day_at(25, '18:00'), pg_temp.day_at(25, '22:00'),
    '{draft}', '{KŠ,VH}', '{Kulturní}', '{Literatura}', '{Prezenčně}', '{Dospělí,Mládež}', NULL,
    'Pořádá České centrum, knihovna poskytuje prostory a čtenáře.', TRUE, NULL, TRUE, '{JT}');
SELECT pg_temp.demo_room('Noc literatury – čtení na neobvyklých místech', 'DČ', FALSE, 'Čtení ve tmě s baterkami.');

-- -------------------------------------------------------------
-- Building code duty for the coming days
-- -------------------------------------------------------------

INSERT INTO building_code_duty (duty_date, primary_user_id, substitute_user_id, note)
VALUES
    (CURRENT_DATE,     pg_temp.user_id('VH'), pg_temp.user_id('PK'), NULL),
    (CURRENT_DATE + 1, pg_temp.user_id('KŠ'), pg_temp.user_id('VH'), NULL),
    (CURRENT_DATE + 2, pg_temp.user_id('HM'), pg_temp.user_id('KŠ'), 'Kurz italštiny končí 16:30'),
    (CURRENT_DATE + 3, pg_temp.user_id('VH'), pg_temp.user_id('PK'), NULL),
    (CURRENT_DATE + 4, pg_temp.user_id('KŠ'), NULL,                  'Ráno lekce pro SŠ');

DROP FUNCTION pg_temp.demo_cost(TEXT, TEXT, TEXT, NUMERIC, TEXT, TEXT, TEXT);
DROP FUNCTION pg_temp.demo_income(TEXT, TEXT, TEXT, NUMERIC, TEXT, TEXT, TEXT, TEXT);
DROP FUNCTION pg_temp.demo_attendance(TEXT, TEXT, TEXT, INTEGER, TEXT);
DROP FUNCTION pg_temp.demo_room(TEXT, TEXT, BOOLEAN, TEXT, TIMESTAMP);
DROP FUNCTION pg_temp.demo_action(TEXT, TEXT, TIMESTAMP, TIMESTAMP, TEXT[], TEXT[], TEXT[], TEXT[], TEXT[], TEXT[], INTEGER, TEXT, BOOLEAN, TEXT, BOOLEAN, TEXT[], TEXT[]);
DROP FUNCTION pg_temp.user_id(TEXT);
DROP FUNCTION pg_temp.day_at(INTEGER, TIME);

COMMIT;
