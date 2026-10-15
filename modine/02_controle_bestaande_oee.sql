USE [modine_db];
GO
SELECT @@SERVERNAME AS servernaam, DB_NAME() AS database_naam;
SELECT COUNT_BIG(*) AS alle_registraties,
       MIN(datum) AS oudste_datum,
       MAX(datum) AS nieuwste_datum
FROM dbo.regels;
SELECT datum, COUNT_BIG(*) AS registraties
FROM dbo.regels
GROUP BY datum
ORDER BY datum DESC;
SELECT TOP (50) id, reg_id, datum, aanvraag, product, persenrij, oven, status, aangemaakt
FROM dbo.regels
ORDER BY id DESC;