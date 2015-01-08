eblah_to_smf
============

**Eblah 10.x.x to SMF 2.x Forum Converter**

Currently only works from command line. 

Copy convert.php, and eblah_to_smf.php to the directory where SMF is currently installed. 

Change to this directory and then run:

php convert.php --path_to=/full/path/to/smf \ <br>
--path_from=/full/path/to/eblah \ <br>
--convert_script=eblah_to_smf.php\ <br>
--db_pass='123456' --debug=1 --db_purge=1

http://www.simplemachines.org/community/index.php?topic=532123.0
