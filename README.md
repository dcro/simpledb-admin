simpledb-admin
==============

An simple administration script that allows users to view/edit/remove the data stored in Amazon SimpleDB.

The script uses the [PHP AWS SDK v1](https://github.com/amazonwebservices/aws-sdk-for-php). You'll need to make sure you have it installed and available in the PHP include path.

If you're using a SimpleDB region other than `us-east-1`, you'll also need to specify the AWS region in the `getConnection()` function.

For large SimpleDB data sets, the script will only display up to 25000 items but you can easily modifiy it to display more if it's suitable for your use case.