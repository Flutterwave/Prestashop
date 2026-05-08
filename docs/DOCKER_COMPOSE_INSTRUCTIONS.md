# PrestaShop Docker Local Setup

This repository provides a fully containerized PrestaShop environment for local development and testing using Docker.

It simplifies the setup process for developers working on modules, themes, or integrations by providing a ready-to-run PrestaShop instance.

## 📦 Prerequisites

Before you begin, ensure you have the following installed:

- Docker
- Docker Compose

## 🚀 Getting Started

1. Start the Docker environment

Run the following command:

```shell
docker-compose up -d
```

This will spin up:
- PrestaShop application container
- Database container (MySQL/MariaDB)

2. Access the installer
Open your browser and go to:
```shell
http://localhost:8084/install144/
```
This will launch the PrestaShop installation wizard.

3. Run the installation wizard
Follow the on-screen setup steps until you reach the database configuration page.

4. Configure the database
Use the following credentials:

```.env
DB_SERVER=db
DB_NAME=prestashop
DB_USER=root
DB_PASSWD=admin
```
👉 Click “Test your database connection” before proceeding.

5. Complete installation
Proceed with the installation and wait for it to finish.

⚠️ Known Issue: Admin folder rename error

After installation, you may encounter an error like:

“Could not rename admin folder”

This is a common PrestaShop setup issue in containerized environments.

## 🔧 Fix
Run the following commands inside the PrestaShop container:
```shell
docker exec -it prestashop /bin/bash
```

Then execute:
```shell
mv admin adminXXXXXXXXX
rm -rf install
```
Replace adminXXXXXXXXX with the actual generated admin folder name.

## 🔐 Access Back Office

Once fixed, access the admin panel:

```shell
http://localhost:8084/adminXXXXXXXXX
```

## 🔑 Default Login Credentials

Use the credentials you setup during the installation phase:

- Email: EMAIL USE DURING INSTALLATION
- Password: PASSWORD USE DURING INSTALLATION

## 🧹 Post-Installation Checklist

After setup, ensure the following:

 - install/ directory is removed
 - Admin folder is renamed
 - You can log into back office
 - Database connection is stable

## 🛠 Troubleshooting
### Port 8084 not accessible
- Ensure no other service is using the port
- Run `docker ps` to verify containers are running

### Admin page not found
- Ensure admin folder rename was completed correctly
- Clear browser cache or try incognito mode

### 📌 Notes
This setup is intended for local development only
- Do not use these credentials in production
- Always secure your PrestaShop instance before deploying

## How to Setup the Module.

1. Search for Flutterwave Payment.
2. Click `Configure` to setup the Payment Module.
3. Enter your Keys and Webhook Hash and Save.
4. Setup a Carrier with a defined Weight limit.
5. Toggle the Free Carrier option.
6. To Create a Product go to Catalog on the side navigation bar and select Products.
7. Choose a Product and Setup Shipping for the product
8. Select the Free Shipping Carrier you created in step 

