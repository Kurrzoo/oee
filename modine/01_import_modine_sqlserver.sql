-- Modine OEE: SQL Server Express 2025 / SSMS 22
-- Bron: modine_db.sql, export 24-09-2026 10:10; 5 tabellen, 33 rijen.
-- Eenmalige import. Bestaande OEE-tabellen worden NIET verwijderd of overschreven.
-- Voer het volledige bestand uit in een nieuw queryvenster; bewaar de MySQL-back-up.
USE [master];
GO
IF DB_ID(N'modine_db') IS NULL
    EXEC(N'CREATE DATABASE [modine_db] COLLATE Latin1_General_100_CI_AI_SC');
GO
USE [modine_db];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET DATEFORMAT ymd;

IF DB_NAME() <> N'modine_db'
    THROW 50000, N'Verkeerde database: import gestopt.', 1;
IF @@TRANCOUNT <> 0
    THROW 50001, N'Open een nieuw queryvenster zonder lopende transactie.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    -- Stop ook bij lege bestaande tabellen: nooit ongemerkt opnieuw importeren.

    IF OBJECT_ID(N'dbo.regels') IS NOT NULL
       OR OBJECT_ID(N'dbo.productwaarden') IS NOT NULL
       OR OBJECT_ID(N'dbo.status_historie') IS NOT NULL
       OR OBJECT_ID(N'dbo.storingen') IS NOT NULL
       OR OBJECT_ID(N'dbo.wijzigingen') IS NOT NULL
        THROW 50002, N'Een OEE-tabel bestaat al. Er is niets overschreven. Controleer de database.', 1;

    CREATE TABLE [dbo].[regels] (
        [id] int IDENTITY(1,1) NOT NULL,
        [reg_id] nvarchar(20) COLLATE Latin1_General_100_CI_AI_SC NOT NULL,
        [aanvraag] nvarchar(50) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [datum] date NULL,
        [product] nvarchar(100) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [persenrij] nvarchar(100) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [oven] nvarchar(100) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [status] nvarchar(50) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [aangemaakt] nvarchar(10) COLLATE Latin1_General_100_CI_AI_SC NULL,
        CONSTRAINT [PK_regels] PRIMARY KEY ([id])
    );

    CREATE TABLE [dbo].[productwaarden] (
        [id] int IDENTITY(1,1) NOT NULL,
        [regel_id] int NULL,
        [naam] nvarchar(100) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [waarde] nvarchar(50) COLLATE Latin1_General_100_CI_AI_SC NULL,
        CONSTRAINT [PK_productwaarden] PRIMARY KEY ([id]),
        CONSTRAINT [FK_productwaarden_regels] FOREIGN KEY ([regel_id]) REFERENCES [dbo].[regels] ([id]) ON DELETE CASCADE
    );

    CREATE INDEX [IX_productwaarden_regel_id] ON [dbo].[productwaarden] ([regel_id]);

    CREATE TABLE [dbo].[status_historie] (
        [id] int IDENTITY(1,1) NOT NULL,
        [regel_id] int NOT NULL,
        [status] nvarchar(20) COLLATE Latin1_General_100_CI_AI_SC NOT NULL,
        [tijd] nvarchar(20) COLLATE Latin1_General_100_CI_AI_SC NOT NULL,
        [extra] nvarchar(255) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [aangemaakt] datetime2(0) NULL CONSTRAINT [DF_status_historie_aangemaakt] DEFAULT (SYSDATETIME()),
        CONSTRAINT [PK_status_historie] PRIMARY KEY ([id]),
        CONSTRAINT [FK_status_historie_regels] FOREIGN KEY ([regel_id]) REFERENCES [dbo].[regels] ([id]) ON DELETE CASCADE
    );

    CREATE INDEX [IX_status_historie_regel_id] ON [dbo].[status_historie] ([regel_id]);

    CREATE TABLE [dbo].[storingen] (
        [id] int IDENTITY(1,1) NOT NULL,
        [regel_id] int NULL,
        [type] nvarchar(100) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [tijdstip] nvarchar(10) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [duur] nvarchar(20) COLLATE Latin1_General_100_CI_AI_SC NULL,
        CONSTRAINT [PK_storingen] PRIMARY KEY ([id]),
        CONSTRAINT [FK_storingen_regels] FOREIGN KEY ([regel_id]) REFERENCES [dbo].[regels] ([id]) ON DELETE CASCADE
    );

    CREATE INDEX [IX_storingen_regel_id] ON [dbo].[storingen] ([regel_id]);

    CREATE TABLE [dbo].[wijzigingen] (
        [id] int IDENTITY(1,1) NOT NULL,
        [regel_id] int NOT NULL,
        [veld] nvarchar(50) COLLATE Latin1_General_100_CI_AI_SC NOT NULL,
        [oude_waarde] nvarchar(255) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [nieuwe_waarde] nvarchar(255) COLLATE Latin1_General_100_CI_AI_SC NULL,
        [gewijzigd_op] datetime2(0) NULL CONSTRAINT [DF_wijzigingen_gewijzigd_op] DEFAULT (SYSDATETIME()),
        CONSTRAINT [PK_wijzigingen] PRIMARY KEY ([id]),
        CONSTRAINT [FK_wijzigingen_regels] FOREIGN KEY ([regel_id]) REFERENCES [dbo].[regels] ([id]) ON DELETE CASCADE
    );

    CREATE INDEX [IX_wijzigingen_regel_id] ON [dbo].[wijzigingen] ([regel_id]);

    SET IDENTITY_INSERT [dbo].[regels] ON;
    INSERT INTO [dbo].[regels] ([id], [reg_id], [aanvraag], [datum], [product], [persenrij], [oven], [status], [aangemaakt]) VALUES

        (20, N'REG-020', N'1343', N'2026-09-22', N'Condensor D9', N'Persenrij 3', N'Aluminium Oven 8', N'afgerond', N'10:51'),
        (21, N'REG-021', N'wdw2', N'2026-09-22', N'Condensor D9', N'Persenrij 2', N'IJzer Oven 20', N'start', N'13:37'),
        (22, N'REG-022', N'eee', N'2026-09-22', N'Ladeluchtkoeler E3', N'Persenrij 2', N'IJzer Oven 20', N'start', N'13:38'),
        (23, N'REG-023', N'2223', N'2026-09-23', N'Condensor D9', N'Persenrij 2', N'Aluminium Oven 8', N'start', N'13:35');

    SET IDENTITY_INSERT [dbo].[regels] OFF;

    DBCC CHECKIDENT (N'dbo.regels', RESEED, 23) WITH NO_INFOMSGS;

    SET IDENTITY_INSERT [dbo].[productwaarden] ON;
    INSERT INTO [dbo].[productwaarden] ([id], [regel_id], [naam], [waarde]) VALUES

        (39, 22, N'Aantal goed', N'3'),
        (40, 22, N'Aantal afkeur', N'4'),
        (43, 20, N'Aantal goed', N'8'),
        (44, 20, N'Aantal afkeur', N'8'),
        (45, 20, N'Cyclustijd (sec)', N'7'),
        (46, 20, N'Stilstandtijd (min)', N'3');

    SET IDENTITY_INSERT [dbo].[productwaarden] OFF;

    DBCC CHECKIDENT (N'dbo.productwaarden', RESEED, 46) WITH NO_INFOMSGS;

    SET IDENTITY_INSERT [dbo].[status_historie] ON;
    INSERT INTO [dbo].[status_historie] ([id], [regel_id], [status], [tijd], [extra], [aangemaakt]) VALUES

        (34, 20, N'start', N'10:51', NULL, N'2026-09-22T10:51:30'),
        (35, 20, N'pauze', N'10:51', NULL, N'2026-09-22T10:51:45'),
        (36, 20, N'hervat', N'10:51', NULL, N'2026-09-22T10:51:46'),
        (37, 20, N'stilstand', N'10:51', N'Wachten op operator — w3', N'2026-09-22T10:51:46'),
        (38, 20, N'hervat', N'10:51', NULL, N'2026-09-22T10:51:51'),
        (39, 20, N'afgerond', N'10:51', NULL, N'2026-09-22T10:51:52'),
        (40, 21, N'start', N'13:37', NULL, N'2026-09-22T13:37:21'),
        (41, 22, N'start', N'13:38', NULL, N'2026-09-22T13:38:04'),
        (42, 23, N'start', N'13:35', NULL, N'2026-09-23T13:35:20');

    SET IDENTITY_INSERT [dbo].[status_historie] OFF;

    DBCC CHECKIDENT (N'dbo.status_historie', RESEED, 42) WITH NO_INFOMSGS;

    SET IDENTITY_INSERT [dbo].[storingen] ON;
    INSERT INTO [dbo].[storingen] ([id], [regel_id], [type], [tijdstip], [duur]) VALUES

        (16, 20, N'Machinestop', N'10:51', N'33'),
        (17, 20, N'Automatische stilstand', N'10:51', N'0.08'),
        (18, 22, N'Persstoring', N'13:38', N'0.06'),
        (19, 20, N'Machinestop', N'14:05', N'5');

    SET IDENTITY_INSERT [dbo].[storingen] OFF;

    DBCC CHECKIDENT (N'dbo.storingen', RESEED, 19) WITH NO_INFOMSGS;

    SET IDENTITY_INSERT [dbo].[wijzigingen] ON;
    INSERT INTO [dbo].[wijzigingen] ([id], [regel_id], [veld], [oude_waarde], [nieuwe_waarde], [gewijzigd_op]) VALUES

        (38, 20, N'Storing toegevoegd', NULL, N'Machinestop: 33 min', N'2026-09-22T10:51:40'),
        (39, 20, N'Stilstandreden', NULL, N'Wachten op operator — w3', N'2026-09-22T10:51:51'),
        (40, 22, N'Storing toegevoegd', NULL, N'Persstoring: 0.06 min', N'2026-09-22T13:38:35'),
        (41, 22, N'Productiegegeven', N'Aantal goed: leeg', N'Aantal goed: 3', N'2026-09-22T13:39:06'),
        (42, 22, N'Productiegegeven', N'Aantal afkeur: leeg', N'Aantal afkeur: 4', N'2026-09-22T13:39:06'),
        (43, 20, N'Productiegegeven', N'Aantal goed: leeg', N'Aantal goed: 8', N'2026-09-22T14:06:00'),
        (44, 20, N'Productiegegeven', N'Aantal afkeur: leeg', N'Aantal afkeur: 8', N'2026-09-22T14:06:00'),
        (45, 20, N'Storing toegevoegd', NULL, N'Machinestop: 5 min', N'2026-09-22T14:06:10'),
        (46, 20, N'Productiegegeven', N'Cyclustijd (sec): leeg', N'Cyclustijd (sec): 7', N'2026-09-22T14:06:30'),
        (47, 20, N'Productiegegeven', N'Stilstandtijd (min): leeg', N'Stilstandtijd (min): 3', N'2026-09-22T14:06:30');

    SET IDENTITY_INSERT [dbo].[wijzigingen] OFF;

    DBCC CHECKIDENT (N'dbo.wijzigingen', RESEED, 47) WITH NO_INFOMSGS;

    IF (SELECT COUNT_BIG(*) FROM [dbo].[regels]) <> 4
        THROW 50100, N'Controle mislukt: onverwacht aantal rijen in regels.', 1;

    IF (SELECT COUNT_BIG(*) FROM [dbo].[productwaarden]) <> 6
        THROW 50101, N'Controle mislukt: onverwacht aantal rijen in productwaarden.', 1;

    IF (SELECT COUNT_BIG(*) FROM [dbo].[status_historie]) <> 9
        THROW 50102, N'Controle mislukt: onverwacht aantal rijen in status_historie.', 1;

    IF (SELECT COUNT_BIG(*) FROM [dbo].[storingen]) <> 4
        THROW 50103, N'Controle mislukt: onverwacht aantal rijen in storingen.', 1;

    IF (SELECT COUNT_BIG(*) FROM [dbo].[wijzigingen]) <> 10
        THROW 50104, N'Controle mislukt: onverwacht aantal rijen in wijzigingen.', 1;

    COMMIT TRANSACTION;
    PRINT N'Import geslaagd. Controleer hieronder de aantallen en volgende IDs.';
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    -- Bij een fout: sluit dit queryvenster om eventuele sessie-instellingen te wissen.
    THROW;
END CATCH;


SELECT N'regels' AS [tabel], COUNT_BIG(*) AS [aantal_rijen], CAST(IDENT_CURRENT(N'dbo.regels') + 1 AS int) AS [volgende_id] FROM [dbo].[regels]
UNION ALL
SELECT N'productwaarden' AS [tabel], COUNT_BIG(*) AS [aantal_rijen], CAST(IDENT_CURRENT(N'dbo.productwaarden') + 1 AS int) AS [volgende_id] FROM [dbo].[productwaarden]
UNION ALL
SELECT N'status_historie' AS [tabel], COUNT_BIG(*) AS [aantal_rijen], CAST(IDENT_CURRENT(N'dbo.status_historie') + 1 AS int) AS [volgende_id] FROM [dbo].[status_historie]
UNION ALL
SELECT N'storingen' AS [tabel], COUNT_BIG(*) AS [aantal_rijen], CAST(IDENT_CURRENT(N'dbo.storingen') + 1 AS int) AS [volgende_id] FROM [dbo].[storingen]
UNION ALL
SELECT N'wijzigingen' AS [tabel], COUNT_BIG(*) AS [aantal_rijen], CAST(IDENT_CURRENT(N'dbo.wijzigingen') + 1 AS int) AS [volgende_id] FROM [dbo].[wijzigingen];
