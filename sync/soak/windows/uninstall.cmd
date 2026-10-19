@echo off
rem Remove the Joinery Drive soak from this computer: its tasks, its two
rem Windows accounts, its firewall rules and C:\soak-win. Double-click it.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-NoExit','-File','C:\soak-win\uninstall.ps1'"
