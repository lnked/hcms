ALTER TABLE cms_users
    ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'admin' AFTER status;

UPDATE cms_users
SET role = 'owner'
WHERE id = (SELECT min_id FROM (SELECT MIN(id) AS min_id FROM cms_users) AS t);
