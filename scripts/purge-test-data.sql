-- fabricate_forge/scripts/purge-test-data.sql
-- RUN MANUALLY (agent guard rails: no DELETE FROM in project code).
--   psql -h 127.0.0.1 -U forge -d fabricate_forge -f scripts/purge-test-data.sql
--
-- Removes everything owned by @test.fabricate / api-test@fabricate.local
-- accounts + their entities/components/links. Children first, wrapped in a
-- transaction. Review the SELECT counts before committing.
--
-- Guard: these tables are ECS-owned (entity/component/link); the WHEREs are
-- strictly owner-scoped so no real user data can be touched.

BEGIN;

-- 0) Review counts (uncomment to inspect before deleting):
-- SELECT count(*) FROM component WHERE user_id_owner IN (SELECT id FROM "user" WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local');
-- SELECT count(*) FROM link      WHERE user_id_owner IN (SELECT id FROM "user" WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local');
-- SELECT count(*) FROM entity    WHERE user_id_owner IN (SELECT id FROM "user" WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local');

-- 1) Children first (component/link reference entity)
DELETE FROM component
 WHERE user_id_owner IN (SELECT id FROM "user" WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local');

DELETE FROM link
 WHERE user_id_owner IN (SELECT id FROM "user" WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local');

-- 2) Entities
DELETE FROM entity
 WHERE user_id_owner IN (SELECT id FROM "user" WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local');

-- 3) The accounts themselves
DELETE FROM auth
 WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local');

DELETE FROM "user"
 WHERE email LIKE '%@test.fabricate' OR email = 'api-test@fabricate.local';

COMMIT;
