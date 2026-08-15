CREATE TABLE `loinc_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode_rs` varchar(20) DEFAULT NULL,
  `loinc_code` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
