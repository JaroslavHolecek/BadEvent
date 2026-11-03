BEGIN;

INSERT INTO currencies (code, name, symbol, decimal_places) VALUES
    ('CZK', 'Korun českých', 'Kč', 2),
    ('EUR', 'Euro', '€', 2);

INSERT INTO action_statuses (name, code) VALUES
    ('Rozjednaná', 'draft'),
    ('Nedojednaná', 'not_agreed'),
    ('Potvrzená', 'confirmed'),
    ('Proběhlá', 'completed'),
    ('Zrušená', 'cancelled'),
    ('Uzavřená', 'closed');

INSERT INTO roles (name, code) VALUES
    ('Administrátor', 'administrator'),
    ('Organizátor', 'organizer'),
    ('Zástup organizátora', 'substitute'),
    ('Pomocník', 'stuff'),
    ('Pozorovatel', 'viewer'),
    ('Správce místnosti', 'place_manager');

INSERT INTO operations (name, code) VALUES -- event is just grouping of actions, so permission are the same (if you can create action, you can create event too etc.)
    ('Vytvořit akci', 'action.create'),
    ('Odvolat akci', 'action.mark_not_agreed'),
    ('Potvrdit akci', 'action.confirm'),
    ('Zrušit akci', 'action.cancel'),
    ('Označit akci jako proběhlou', 'action.complete'),
    ('Uzavřít akci', 'action.close'),
    ('Smazat akci', 'action.delete'),
    
    ('Upravit běžné údaje akce', 'action.update_basic'),
    ('Upravit běžné údaje vlastní akce', 'action.own_action.update_basic'),
    ('Upravit ekonomické údaje akce', 'action.update_economic'),

    ('Zobrazit běžné údaje akce', 'action.view_basic'),
    ('Zobrazit ekonomické údaje akce', 'action.view_economic'),
    
    ('Zobrazit archiv všech akcí', 'archive.view'),

    ('Spravovat uživatele', 'users.manage'),
    ('Spravovat číselníky', 'dictionaries.manage'),

    ('Spravovat osoby a kontakty', 'person.manage'),
    ('Zobrazit osoby a kontakty', 'person.view'),

    ('Spravovat kódování budovy', 'building_code_duty.manage');

INSERT INTO contact_types (name, abbreviation) VALUES
    ('Telefon', 'tel'),
    ('Email', 'email');

INSERT INTO visitor_types (name) VALUES
    ('Platící'),
    ('Neplatící');

INSERT INTO action_types (name, abbreviation) VALUES
    ('Kulturní', 'kult'),
    ('Vzdělávací', 'vzděl'),
    ('Sociální', 'soc');

INSERT INTO visitor_categories (name) VALUES
    ('Děti'),
    ('Mládež'),
    ('Dospělí'),
    ('Senioři');

INSERT INTO income_types (name) VALUES
    ('Vstupné'),
    ('Dotace'),
    ('Dar');

INSERT INTO income_subtypes (income_type_id, name)
    SELECT id, 'Web'      FROM income_types WHERE name = 'Vstupné'
    UNION ALL
    SELECT id, 'Hotovost' FROM income_types WHERE name = 'Vstupné'
    UNION ALL
    SELECT id, 'Faktura'  FROM income_types WHERE name = 'Vstupné';

INSERT INTO cost_types (name) VALUES
    ('DPP'),
    ('Faktura'),
    ('Materiál'),
    ('Služba');

-- ============================================================
-- Role → Operations permissions
-- Administrator: all operations
-- Organizer: create/edit/confirm/complete/cancel actions and events
-- Substitute: view archive only
-- Reader: view archive only
-- ============================================================

INSERT INTO role_operations (role_id, operation_id)
SELECT r.id, o.id
FROM roles r
CROSS JOIN operations o
WHERE r.code = 'administrator';

INSERT INTO role_operations (role_id, operation_id)
SELECT r.id, o.id
FROM roles r, operations o
WHERE r.code = 'organizer'
  AND o.code IN (
    'action.create',
    'action.mark_not_agreed',
    'action.confirm',
    'action.complete',
    'action.own_action.update_basic',
    'action.view_basic',
    'action.view_economic',
    'archive.view',
    'person.manage',
    'person.view'
  );

INSERT INTO role_operations (role_id, operation_id)
SELECT r.id, o.id
FROM roles r, operations o
WHERE r.code = 'substitute'
  AND o.code IN (
    'action.view_basic');

INSERT INTO role_operations (role_id, operation_id)
SELECT r.id, o.id
FROM roles r, operations o
WHERE r.code = 'stuff'
  AND o.code IN ('action.view_basic');

INSERT INTO role_operations (role_id, operation_id)
SELECT r.id, o.id
FROM roles r, operations o
WHERE r.code = 'viewer'
  AND o.code IN (
    'action.view_basic',
    'archive.view');

INSERT INTO role_operations (role_id, operation_id)
SELECT r.id, o.id
FROM roles r, operations o
WHERE r.code = 'place_manager'
  AND o.code IN ('action.view_basic');

COMMIT;
