

1. docker-compose up -d
2. Navigate to `http://localhost:8084/install144/`
3. Run through the installation assistant. 
4. Enter the database details

```.env
    DB_SERVER: db
    DB_NAME: prestashop
    DB_USER: root
    DB_PASSWD: admin
```
5. test the connection to the db first.
6. Wait for the setup to complete.
7. you will see the error could not rename to adminXXXXXX

run the command below:
```shell
docker exec -it prestashop /bin/bash
mv admin adminXXXXXXXXX
rm -rf install
```

8. go to `http://localhost:8084/adminXXXXXXXXX`
9. login with the email `demo@prestashop.com` and password `prestashop_demo`