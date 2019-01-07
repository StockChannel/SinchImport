1 : IMPORT FULL COMMAND.
php -d memory_limit=-1 -d set_time_limit=0 bin/magento sinch:import full

2 : RUN GENERATE REWRITES URL
php -d memory_limit=-1 -d set_time_limit=0 bin/magento sinch:url:generate

3 : IMPORT STOCKPRICE COMMAND.
php bin/magento sinch:import stockprice

4 : TONERFIDER COMMAND.
php bin/magento sinch:tonerfinder

-- FIX SOME BASIC BUGS --
Fix errors Step :
--Truncate all categories...
=> check user permission mysql (Set permission for user).