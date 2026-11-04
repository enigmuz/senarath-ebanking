/* ============================================================================
   Senarath Bank — SenarathLab database schema  (Microsoft SQL Server 2019/2022)
   LAB ENVIRONMENT · synthetic data only · run on SQL01 (internal host)
   ----------------------------------------------------------------------------
   Run with sqlcmd on SQL01, e.g.:
     sqlcmd -S localhost -U sa -P <sa_password> -i 01_schema.sql
   (or open in SSMS and execute).

   Creates: database SenarathLab, all application tables, and the lab_app SQL
   login used by the web origin (V6 credentials: lab_app / DbLab123!).
   ============================================================================ */

-------------------------------------------------------------------------------
-- 1. Database
-------------------------------------------------------------------------------
IF DB_ID('SenarathLab') IS NULL
BEGIN
    CREATE DATABASE SenarathLab;
END
GO
ALTER DATABASE SenarathLab SET RECOVERY SIMPLE;
GO
USE SenarathLab;
GO

-------------------------------------------------------------------------------
-- 2. Drop existing tables (idempotent rebuild)
-------------------------------------------------------------------------------
IF OBJECT_ID('dbo.transactions','U')  IS NOT NULL DROP TABLE dbo.transactions;
IF OBJECT_ID('dbo.uploads','U')       IS NOT NULL DROP TABLE dbo.uploads;
IF OBJECT_ID('dbo.sessions','U')      IS NOT NULL DROP TABLE dbo.sessions;
IF OBJECT_ID('dbo.beneficiaries','U') IS NOT NULL DROP TABLE dbo.beneficiaries;
IF OBJECT_ID('dbo.accounts','U')      IS NOT NULL DROP TABLE dbo.accounts;
IF OBJECT_ID('dbo.customers','U')     IS NOT NULL DROP TABLE dbo.customers;
GO

-------------------------------------------------------------------------------
-- 3. Tables
-------------------------------------------------------------------------------
CREATE TABLE dbo.customers (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    customer_no   VARCHAR(10)  NOT NULL UNIQUE,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash CHAR(64)     NOT NULL,          -- SHA-256 hex (lowercase)
    first_name    NVARCHAR(50) NOT NULL,
    last_name     NVARCHAR(50) NOT NULL,
    email         NVARCHAR(120) NULL,
    phone         VARCHAR(20)  NULL,
    nic           VARCHAR(20)  NULL,
    address       NVARCHAR(200) NULL,
    city          NVARCHAR(50) NULL,
    dob           DATE         NULL,
    is_active     BIT          NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT GETDATE()
);

CREATE TABLE dbo.accounts (
    id                INT IDENTITY(1,1) PRIMARY KEY,
    customer_id       INT NOT NULL REFERENCES dbo.customers(id),
    account_no        VARCHAR(16)  NOT NULL UNIQUE,
    account_type      VARCHAR(10)  NOT NULL,       -- Current / Savings / Credit
    currency          CHAR(3)      NOT NULL DEFAULT 'LKR',
    balance           DECIMAL(18,2) NOT NULL DEFAULT 0,
    available_balance DECIMAL(18,2) NOT NULL DEFAULT 0,
    opened_at         DATE          NOT NULL DEFAULT GETDATE(),
    status            VARCHAR(10)   NOT NULL DEFAULT 'Active'
);
CREATE INDEX IX_accounts_customer ON dbo.accounts(customer_id);

CREATE TABLE dbo.transactions (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    account_id    INT NOT NULL REFERENCES dbo.accounts(id),
    txn_date      DATETIME      NOT NULL,
    description   NVARCHAR(120) NOT NULL,
    counterparty  NVARCHAR(120) NULL,
    amount        DECIMAL(18,2) NOT NULL,          -- signed: +credit / -debit
    balance_after DECIMAL(18,2) NOT NULL,
    channel       VARCHAR(20)   NOT NULL DEFAULT 'Online',
    status        VARCHAR(20)   NOT NULL DEFAULT 'Completed',
    reference     VARCHAR(40)   NULL
);
CREATE INDEX IX_txn_account ON dbo.transactions(account_id, txn_date);

CREATE TABLE dbo.beneficiaries (
    id          INT IDENTITY(1,1) PRIMARY KEY,
    customer_id INT NOT NULL REFERENCES dbo.customers(id),
    name        NVARCHAR(100) NOT NULL,
    bank        NVARCHAR(60)  NOT NULL DEFAULT 'Senarath Bank',
    account_no  VARCHAR(20)   NOT NULL,
    nickname    NVARCHAR(60)  NULL,
    created_at  DATETIME      NOT NULL DEFAULT GETDATE()
);

CREATE TABLE dbo.sessions (
    token         VARCHAR(64) PRIMARY KEY,
    customer_id   INT NOT NULL REFERENCES dbo.customers(id),
    authenticated BIT NOT NULL DEFAULT 0,
    otp_verified  BIT NOT NULL DEFAULT 0,
    otp_code      VARCHAR(6)  NULL,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    expires_at    DATETIME NOT NULL
);

CREATE TABLE dbo.uploads (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    customer_id   INT NOT NULL REFERENCES dbo.customers(id),
    original_name NVARCHAR(260) NOT NULL,
    stored_name   NVARCHAR(260) NOT NULL,
    stored_path   NVARCHAR(400) NULL,
    size_bytes    INT NULL,
    mime          VARCHAR(120) NULL,
    uploaded_at   DATETIME NOT NULL DEFAULT GETDATE()
);
GO

-------------------------------------------------------------------------------
-- 4. Application SQL login  (V6 credentials: lab_app / DbLab123!)
--    The DMZ web origin authenticates to SQL01 with this login over TCP 1433.
--    Requires the instance to allow SQL (mixed-mode) authentication.
-------------------------------------------------------------------------------
IF SUSER_ID('lab_app') IS NULL
BEGIN
    CREATE LOGIN lab_app WITH PASSWORD = 'DbLab123!',
         CHECK_POLICY = OFF, DEFAULT_DATABASE = SenarathLab;
END
GO
USE SenarathLab;
GO
IF USER_ID('lab_app') IS NULL
    CREATE USER lab_app FOR LOGIN lab_app;
GO
-- The application needs read/write on SenarathLab:
ALTER ROLE db_owner ADD MEMBER lab_app;
GO

/* ---------------------------------------------------------------------------
   OPTIONAL — Infrastructure / AD team step D1 (NOT required for the web app).
   The build guide maps the V6 credentials to a SQL login granted the sysadmin
   server role so the attack chain can enable + run xp_cmdshell (stage 10).
   Enable ONLY on the isolated lab SQL01, per the Infrastructure team's build:

     ALTER SERVER ROLE sysadmin ADD MEMBER lab_app;
     -- EXEC sp_configure 'show advanced options', 1; RECONFIGURE;
     -- EXEC sp_configure 'xp_cmdshell', 1; RECONFIGURE;
   --------------------------------------------------------------------------- */
GO
PRINT 'SenarathLab schema created. Next: run 02_seed_customers.sql';
GO
