@echo off
rem Pont MARWA SECEF CONNECT : laissez cette fenetre ouverte pendant les ventes certifiees.
cd /d "%~dp0"
title Pont MARWA SECEF CONNECT
where node >nul 2>nul || (echo Node.js est introuvable : installez Node.js 18 ou plus. & pause & exit /b 1)
if not exist node_modules\playwright-core (echo Installation des dependances... & call npm install --omit=dev || (pause & exit /b 1))
if not exist jeton.txt (echo Fichier jeton.txt absent : creez le jeton dans POS Marwa ^(Certification fiscale - MARWA SECEF CONNECT^) et collez-le dans jeton.txt. & pause & exit /b 1)
node serveur.js
pause
