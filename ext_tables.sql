CREATE TABLE tx_solrcontentapi_domain_model_apikey (
    label varchar(255) DEFAULT '' NOT NULL,
    api_key varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_solrcontentapi_file_deletion (
    uid int unsigned NOT NULL AUTO_INCREMENT,
    document_uid int unsigned DEFAULT '0' NOT NULL,
    deleted_at int unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    KEY deleted_at (deleted_at)
);
