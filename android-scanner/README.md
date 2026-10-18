# Maruhat Attendance

Native Android Studio companion for the Biometric Attendance System.

## Open and run

1. Open the `android-scanner` folder in Android Studio.
2. Let Android Studio use Android SDK Platform 35 and sync the project.
3. Run it on a camera-enabled Android phone or emulator.
4. In the system, open **Admin Settings > Mobile scanner configuration** and save the local HTTP IP address, for example `http://192.168.1.10/biometric/`. Do not use `https://` with a local XAMPP IP.
5. Enter that same address in the Maruhat Attendance app.

For a physical phone, enter the computer's LAN address in the app, for example `http://192.168.1.10/biometric/`. The phone and XAMPP computer must be on the same Wi-Fi network. Use `http://10.0.2.2/biometric/` only from the Android emulator.

The app saves only the server address on the device. It does not require or save an account or password.

## Backend endpoints

The Android app uses the session-protected PHP endpoints in `api/`:

- `mobile-login.php`
- `mobile-session.php`
- `mobile-scan.php`
- `mobile-logout.php`

They use the configured school-server address and the same facial-recognition attendance path as the guard-house scanner.

