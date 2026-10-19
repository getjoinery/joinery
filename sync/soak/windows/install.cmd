@echo off
rem Install the Joinery Drive soak on this computer. Double-click it: Windows
rem asks for administrator rights once, and the window says what it did.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-NoExit','-File','\"%~dp0install_pc.ps1\"'"
