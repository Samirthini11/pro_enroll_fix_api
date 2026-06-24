-- Remove foreign key constraints; keep id reference columns and indexes unchanged.
-- Safe to re-run on existing localhost / VPS databases.
USE pro_enroll;

DROP PROCEDURE IF EXISTS drop_all_table_fks;
DELIMITER //
CREATE PROCEDURE drop_all_table_fks(IN p_table VARCHAR(64))
BEGIN
    DECLARE v_fk VARCHAR(64);
    DECLARE done INT DEFAULT FALSE;
    DECLARE cur CURSOR FOR
        SELECT CONSTRAINT_NAME
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND CONSTRAINT_TYPE = 'FOREIGN KEY';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;
    fk_loop: LOOP
        FETCH cur INTO v_fk;
        IF done THEN
            LEAVE fk_loop;
        END IF;
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` DROP FOREIGN KEY `', v_fk, '`');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;
END//
DELIMITER ;

CALL drop_all_table_fks('auth_accounts');
CALL drop_all_table_fks('auth_sessions');
CALL drop_all_table_fks('professional_skills');
CALL drop_all_table_fks('service_bookings');
CALL drop_all_table_fks('booking_ratings');

DROP PROCEDURE IF EXISTS drop_all_table_fks;
