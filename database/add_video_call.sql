ALTER TABLE `appointments`
    ADD COLUMN IF NOT EXISTS `video_room_url` VARCHAR(500) DEFAULT NULL AFTER `symptoms`,
    ADD COLUMN IF NOT EXISTS `video_call_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_room_url`;

CREATE INDEX IF NOT EXISTS `idx_video_call_enabled` ON `appointments` (`video_call_enabled`);

SELECT 'Video call columns added successfully.' AS result;
