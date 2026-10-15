USE [master];
GO
IF CONVERT(int, SERVERPROPERTY('IsIntegratedSecurityOnly')) = 1
    THROW 51000, N'SQL Server staat alleen op Windows Authentication. Zet eerst Mixed Mode aan en herstart de SQL Server (SQL)-service.', 1;
GO
DECLARE @wachtwoord nvarchar(128) = N'12345678';
IF @wachtwoord = N'VUL_HIER_EEN_LANG_UNIEK_WACHTWOORD_IN'
    THROW 51001, N'Vul eerst je eigen wachtwoord in; voorbeeldwachtwoord geweigerd.', 1;
IF SUSER_ID(N'user') IS NULL
BEGIN
    DECLARE @sql nvarchar(max) =
        N'CREATE LOGIN [user] WITH PASSWORD = '
        + QUOTENAME(@wachtwoord, N'''')
        + N', CHECK_POLICY = ON, DEFAULT_DATABASE = [modine_db]';
    EXEC sys.sp_executesql @sql;
END;
GO
USE [modine_db];
GO
IF DATABASE_PRINCIPAL_ID(N'user') IS NULL
    CREATE USER [user] FOR LOGIN [user];
GO
GRANT SELECT, INSERT, UPDATE, DELETE ON SCHEMA::[dbo] TO [user];
GO
SELECT DB_NAME() AS [database], SUSER_ID(N'user') AS [login_id],
       DATABASE_PRINCIPAL_ID(N'user') AS [database_user_id];
