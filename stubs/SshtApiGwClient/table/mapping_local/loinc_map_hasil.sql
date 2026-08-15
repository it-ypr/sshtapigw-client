CREATE TABLE `loinc_map_hasil` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_lab` varchar(15) NOT NULL,
  `loinc_code` varchar(15) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_lab_unique` (`id_lab`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
