CREATE TABLE `loinc_lab_order` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loinc_order_code` varchar(15) DEFAULT NULL,
  `loinc_code` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
