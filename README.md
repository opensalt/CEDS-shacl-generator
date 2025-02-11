This tool generates a SHACL property file from the new CEDS ontology
and exiting element database.

After cloning the repository one can install the libraries using
```bash
composer install
```

The tool using file extracted from the CEDS element database in
`db-extract/`, and the ontology file `CEDS-Ontology.ttl`.

To generate the SHACL property file one can use the following command

```bash
php bin/console app:generate-shacl
```

this will create two files, `output.ttl` and `output-with-comments.ttl`.

The difference between these two files is that the second contains
comments showing the codes beside the opaque identifiers.
