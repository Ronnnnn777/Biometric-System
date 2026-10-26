package ph.edu.maruhat.biometricscanner;

import android.Manifest;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.Rect;
import android.graphics.RectF;
import android.graphics.drawable.GradientDrawable;
import android.media.AudioManager;
import android.media.Ringtone;
import android.media.RingtoneManager;
import android.media.ToneGenerator;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.util.Size;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.camera.core.CameraSelector;
import androidx.camera.core.ImageAnalysis;
import androidx.camera.core.ImageCapture;
import androidx.camera.core.ImageCaptureException;
import androidx.camera.core.ImageProxy;
import androidx.camera.core.Preview;
import androidx.camera.lifecycle.ProcessCameraProvider;
import androidx.camera.view.PreviewView;
import androidx.camera.view.transform.CoordinateTransform;
import androidx.camera.view.transform.ImageProxyTransformFactory;
import androidx.camera.view.transform.OutputTransform;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import com.google.common.util.concurrent.ListenableFuture;
import com.google.mlkit.vision.common.InputImage;
import com.google.mlkit.vision.face.FaceDetection;
import com.google.mlkit.vision.face.FaceDetector;
import com.google.mlkit.vision.face.FaceDetectorOptions;
import java.io.File;
import java.io.IOException;
import java.net.CookieManager;
import java.net.CookiePolicy;
import java.net.Inet4Address;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.net.SocketException;
import java.util.ArrayList;
import java.util.Enumeration;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Set;
import java.util.concurrent.CompletionService;
import java.util.concurrent.ExecutorCompletionService;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import okhttp3.FormBody;
import okhttp3.JavaNetCookieJar;
import okhttp3.MediaType;
import okhttp3.MultipartBody;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import org.json.JSONArray;
import org.json.JSONObject;

/** Native Android companion for MNHS Attendance. */
public class MainActivity extends AppCompatActivity {

  private static final int CAMERA_PERMISSION = 41;
  private final ExecutorService networkExecutor =
    Executors.newSingleThreadExecutor();
  private final ExecutorService imageExecutor =
    Executors.newSingleThreadExecutor();
  private final ExecutorService cameraExecutor =
    Executors.newSingleThreadExecutor();
  private final ExecutorService faceExecutor =
    Executors.newSingleThreadExecutor();
  private final ScheduledExecutorService scanScheduler =
    Executors.newSingleThreadScheduledExecutor();
  private final AtomicBoolean captureInFlight = new AtomicBoolean(false);
  private final AtomicBoolean cameraCaptureBusy = new AtomicBoolean(false);
  private final AtomicBoolean faceVisible = new AtomicBoolean(false);
  private final CookieManager cookieManager = new CookieManager(
    null,
    CookiePolicy.ACCEPT_ORIGINAL_SERVER
  );
  private final OkHttpClient http = new OkHttpClient.Builder()
    .cookieJar(new JavaNetCookieJar(cookieManager))
    .build();
  private final OkHttpClient scanHttp = new OkHttpClient.Builder()
    .cookieJar(new JavaNetCookieJar(cookieManager))
    .connectTimeout(8, TimeUnit.SECONDS)
    .writeTimeout(20, TimeUnit.SECONDS)
    .readTimeout(35, TimeUnit.SECONDS)
    .callTimeout(40, TimeUnit.SECONDS)
    .build();
  private final OkHttpClient discoveryHttp = new OkHttpClient.Builder()
    .connectTimeout(1500, TimeUnit.MILLISECONDS)
    .readTimeout(1500, TimeUnit.MILLISECONDS)
    .writeTimeout(1500, TimeUnit.MILLISECONDS)
    .callTimeout(2200, TimeUnit.MILLISECONDS)
    .build();
  private SharedPreferences preferences;
  private EditText serverInput;
  private TextView loginStatus, scannerStatus, serverDetectionStatus;
  private TextView scanResultAction, scanResultName;
  private Button loginButton, startButton, detectServerButton;
  private Button updateButton;
  private LinearLayout bottomControls;
  private LinearLayout scanResultCard;
  private LinearLayout scanToastCard;
  private ImageView scanResultImage;
  private TextView scanToastIcon;
  private PreviewView previewView;
  private ProcessCameraProvider cameraProvider;
  private ImageCapture imageCapture;
  private ImageAnalysis imageAnalysis;
  private FaceDetector faceDetector;
  private FaceGuideView faceGuide;
  private ScheduledFuture<?> scanTask;
  private ScheduledFuture<?> sessionKeepAliveTask;
  private volatile boolean scanning = false;
  private volatile boolean scannerTransitioning = false;
  private String baseUrl;
  private String scannerName = "Mobile scanner",
    scannerId = "";
  private volatile long matchMessageUntil = 0;
  private volatile long recognitionGraceUntil = 0;
  private volatile long unrecognizedMessageUntil = 0;
  private String guideStatusText = "";
  private int unrecognizedStreak = 0;
  private String lastRecognitionKey = "";
  private boolean returnToScannerHomeOnResume = false;
  private final Handler interfaceHandler = new Handler(Looper.getMainLooper());
  private ToneGenerator recognitionTone;
  private final Runnable hideControls = () -> {
    if (bottomControls != null) bottomControls
      .animate()
      .alpha(0f)
      .setDuration(260)
      .withEndAction(() -> bottomControls.setVisibility(View.INVISIBLE))
      .start();
  };
  private final Runnable hideScanToast = () -> {
    if (scanToastCard != null) scanToastCard
      .animate()
      .alpha(0f)
      .setDuration(320)
      .withEndAction(() -> scanToastCard.setVisibility(View.GONE))
      .start();
  };
  private final Runnable hideScanResult = () -> {
    if (scanResultCard != null) scanResultCard
      .animate()
      .alpha(0f)
      .setDuration(320)
      .withEndAction(() -> scanResultCard.setVisibility(View.GONE))
      .start();
  };
  private long updateDownloadId = -1;
  private String updateUrl = "",
    updateVersion = "";
  private final BroadcastReceiver updateReceiver = new BroadcastReceiver() {
    @Override
    public void onReceive(android.content.Context context, Intent intent) {
      if (
        !DownloadManager.ACTION_DOWNLOAD_COMPLETE.equals(intent.getAction()) ||
        intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1) !=
          updateDownloadId
      ) return;
      DownloadManager manager = (DownloadManager) getSystemService(
        DOWNLOAD_SERVICE
      );
      Uri file = manager.getUriForDownloadedFile(updateDownloadId);
      if (file == null) {
        Toast.makeText(
          MainActivity.this,
          "Update download failed.",
          Toast.LENGTH_LONG
        ).show();
        return;
      }
      Intent install = new Intent(Intent.ACTION_VIEW)
        .setDataAndType(file, "application/vnd.android.package-archive")
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
      startActivity(install);
    }
  };

  @Override
  protected void onCreate(Bundle savedInstanceState) {
    super.onCreate(savedInstanceState);
    preferences = getSharedPreferences("mobile_scanner", MODE_PRIVATE);
    ContextCompat.registerReceiver(
      this,
      updateReceiver,
      new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE),
      ContextCompat.RECEIVER_NOT_EXPORTED
    );
    recognitionTone = new ToneGenerator(AudioManager.STREAM_NOTIFICATION, 100);
    faceDetector = FaceDetection.getClient(
      new FaceDetectorOptions.Builder()
        .setPerformanceMode(FaceDetectorOptions.PERFORMANCE_MODE_FAST)
        .setMinFaceSize(.12f)
        .build()
    );
    restoreSavedScanner();
  }

  private void restoreSavedScanner() {
    String savedServer = normaliseUrl(preferences.getString("serverUrl", ""));
    if (savedServer == null) {
      showLogin();
      return;
    }
    String savedName = automaticScannerName();
    String savedId = automaticScannerId();

    showLogin();
    loginButton.setEnabled(false);
    loginButton.setText("Restoring scanner...");
    showLoginError("Restoring this authorized phone...");
    networkExecutor.execute(() -> {
      RequestBody loginBody = new FormBody.Builder()
        .add("scanner_name", savedName)
        .add("scanner_id", savedId)
        .build();
      Request request = new Request.Builder()
        .url(savedServer + "api/mobile-login.php")
        .post(loginBody)
        .build();
      try (Response response = http.newCall(request).execute()) {
        String payload =
          response.body() == null ? "" : response.body().string();
        JSONObject json = new JSONObject(payload);
        if (
          !response.isSuccessful() || !json.optBoolean("ok")
        ) throw new IOException(
          json.optString("message", "Could not restore scanner.")
        );
        baseUrl = savedServer;
        scannerName = json.optString("scanner_name", savedName);
        scannerId = json.optString("scanner_id", savedId);
        runOnUiThread(this::showScanner);
      } catch (Exception error) {
        runOnUiThread(() -> {
          loginButton.setEnabled(true);
          loginButton.setText("Start scanner");
          showLoginError(
            "Could not restore scanner. Checking the current Wi-Fi for MNHS Attendance..."
          );
          detectServerAddress();
        });
      }
    });
  }

  private void showLogin() {
    stopScanner();
    FrameLayout shell = new FrameLayout(this);
    GradientDrawable background = new GradientDrawable(
      GradientDrawable.Orientation.TL_BR,
      new int[] {
        Color.rgb(30, 58, 95),
        Color.rgb(15, 23, 42),
        Color.rgb(2, 6, 23),
      }
    );
    shell.setBackground(background);
    ImageView watermark = new ImageView(this);
    watermark.setImageResource(R.drawable.maruhat_logo);
    watermark.setAlpha(.10f);
    watermark.setScaleType(ImageView.ScaleType.CENTER_INSIDE);
    FrameLayout.LayoutParams watermarkParams = new FrameLayout.LayoutParams(
      dp(330),
      dp(330),
      Gravity.CENTER
    );
    shell.addView(watermark, watermarkParams);
    ScrollView scroll = new ScrollView(this);
    scroll.setFillViewport(true);
    LinearLayout page = column(0);
    page.setGravity(Gravity.CENTER_HORIZONTAL);
    page.setPadding(dp(28), dp(52), dp(28), dp(30));
    ImageView logo = new ImageView(this);
    logo.setImageResource(R.drawable.maruhat_logo);
    logo.setScaleType(ImageView.ScaleType.CENTER_INSIDE);
    GradientDrawable logoBackground = round(Color.WHITE, 50);
    logo.setBackground(logoBackground);
    logo.setPadding(dp(5), dp(5), dp(5), dp(5));
    LinearLayout.LayoutParams logoParams = new LinearLayout.LayoutParams(
      dp(82),
      dp(82)
    );
    page.addView(logo, logoParams);
    TextView school = text(
      "MARUHAT INTEGRATED SCHOOL",
      12,
      Color.rgb(191, 219, 254)
    );
    school.setLetterSpacing(.12f);
    school.setTypeface(null, 1);
    school.setGravity(Gravity.CENTER);
    LinearLayout.LayoutParams schoolParams = new LinearLayout.LayoutParams(
      -1,
      -2
    );
    schoolParams.topMargin = dp(18);
    page.addView(school, schoolParams);
    TextView title = text("Mobile scanner", 30, Color.WHITE);
    title.setTypeface(null, 1);
    title.setGravity(Gravity.CENTER);
    LinearLayout.LayoutParams titleParams = new LinearLayout.LayoutParams(
      -1,
      -2
    );
    titleParams.topMargin = dp(7);
    page.addView(title, titleParams);
    TextView subtitle = text(
      "Connect this phone to the MNHS Attendance system.",
      15,
      Color.rgb(203, 213, 225)
    );
    subtitle.setGravity(Gravity.CENTER);
    subtitle.setPadding(dp(8), dp(8), dp(8), 0);
    page.addView(subtitle);
    LinearLayout card = column(12);
    card.setPadding(dp(22), dp(22), dp(22), dp(22));
    card.setBackground(round(Color.argb(232, 15, 23, 42), 22));
    LinearLayout.LayoutParams cardParams = new LinearLayout.LayoutParams(
      -1,
      -2
    );
    cardParams.topMargin = dp(30);
    page.addView(card, cardParams);
    TextView cardTitle = text("Configure scanner", 20, Color.WHITE);
    cardTitle.setTypeface(null, 1);
    card.addView(cardTitle);
    TextView cardCopy = text(
      "Enter the school IP address. This phone is identified and added to the Admin Dashboard automatically.",
      13,
      Color.rgb(203, 213, 225)
    );
    card.addView(cardCopy);
    TextView ipLabel = label("School IP address");
    ipLabel.setTextColor(Color.WHITE);
    card.addView(ipLabel);
    serverInput = input("http://192.168.1.10/biometric/");
    serverInput.setText(preferences.getString("serverUrl", ""));
    serverInput.setTextColor(Color.WHITE);
    serverInput.setHintTextColor(Color.rgb(148, 163, 184));
    serverInput.setBackground(round(Color.argb(210, 2, 6, 23), 12));
    card.addView(serverInput);
    detectServerButton = button("Retry IP address detection", Color.rgb(30, 64, 175));
    LinearLayout.LayoutParams detectParams = new LinearLayout.LayoutParams(
      -1,
      dp(48)
    );
    detectParams.topMargin = dp(2);
    card.addView(detectServerButton, detectParams);
    detectServerButton.setVisibility(View.GONE);
    detectServerButton.setOnClickListener(v -> detectServerAddress());
    serverDetectionStatus = text("", 13, Color.rgb(191, 219, 254));
    serverDetectionStatus.setVisibility(View.GONE);
    card.addView(serverDetectionStatus);
    TextView hint = text(
      "The app automatically detects the MNHS Attendance address on the current Wi-Fi. You can also enter it manually.",
      12,
      Color.rgb(191, 219, 254)
    );
    card.addView(hint);
    loginStatus = text("", 14, Color.rgb(253, 164, 175));
    loginStatus.setVisibility(View.GONE);
    card.addView(loginStatus);
    loginButton = button("Start scanner", Color.rgb(37, 99, 235));
    LinearLayout.LayoutParams loginParams = new LinearLayout.LayoutParams(
      -1,
      dp(52)
    );
    loginParams.topMargin = dp(8);
    card.addView(loginButton, loginParams);
    loginButton.setOnClickListener(v -> signIn());
    TextView privacy = text(
      "MNHS Attendance · This phone is automatically registered on the Admin Dashboard.",
      12,
      Color.rgb(203, 213, 225)
    );
    privacy.setGravity(Gravity.CENTER);
    privacy.setPadding(dp(8), dp(20), dp(8), 0);
    page.addView(privacy);
    scroll.addView(page);
    shell.addView(scroll, new FrameLayout.LayoutParams(-1, -1));
    setContentView(shell);
    if (normaliseUrl(serverInput.getText().toString()) == null) {
      interfaceHandler.postDelayed(this::detectServerAddress, 250);
    }
  }

  private void detectServerAddress() {
    if (detectServerButton == null || serverInput == null) return;
    detectServerButton.setEnabled(false);
    detectServerButton.setText("Detecting IP address...");
    if (loginButton != null) loginButton.setEnabled(false);
    showLoginError("");
    setServerDetectionStatus(
      "Searching the current Wi-Fi network for MNHS Attendance...",
      Color.rgb(191, 219, 254)
    );
    networkExecutor.execute(() -> {
      try {
        String detectedUrl = detectSchoolServerUrl();
        if (detectedUrl == null) {
          throw new IOException(
            "MNHS Attendance was not found. Confirm that this phone and the XAMPP computer use the same Wi-Fi."
          );
        }
        runOnUiThread(() -> {
          serverInput.setText(detectedUrl);
          detectServerButton.setVisibility(View.GONE);
          setServerDetectionStatus(
            "Detected " + detectedUrl + ". Tap Start scanner.",
            Color.rgb(134, 239, 172)
          );
        });
      } catch (Exception error) {
        runOnUiThread(() -> {
          detectServerButton.setVisibility(View.VISIBLE);
          setServerDetectionStatus(
            readableError(error),
            Color.rgb(253, 164, 175)
          );
        });
      } finally {
        runOnUiThread(() -> {
          if (detectServerButton != null) {
            detectServerButton.setEnabled(true);
            detectServerButton.setText("Retry IP address detection");
          }
          if (loginButton != null) loginButton.setEnabled(true);
        });
      }
    });
  }

  private String detectSchoolServerUrl() throws IOException {
    Set<String> prefixes = localPrivateIpv4Prefixes();
    if (prefixes.isEmpty()) {
      throw new IOException("Connect this phone to the school Wi-Fi first.");
    }
    List<String> basePaths = discoveryBasePaths();
    ExecutorService probes = Executors.newFixedThreadPool(24);
    CompletionService<String> completed = new ExecutorCompletionService<>(
      probes
    );
    int probeCount = 0;
    try {
      for (String prefix : prefixes) {
        for (int host = 1; host <= 254; host++) {
          final String candidateHost = prefix + host;
          completed.submit(() -> probeSchoolServer(candidateHost, basePaths));
          probeCount++;
        }
      }
      for (int attempt = 0; attempt < probeCount; attempt++) {
        try {
          String serverUrl = completed.take().get();
          if (serverUrl != null) return serverUrl;
        } catch (InterruptedException error) {
          Thread.currentThread().interrupt();
          throw new IOException("IP address detection was cancelled.", error);
        } catch (Exception ignored) {
          // A non-school device may reject the short discovery request.
        }
      }
      return null;
    } finally {
      probes.shutdownNow();
    }
  }

  private Set<String> localPrivateIpv4Prefixes() throws SocketException {
    Set<String> prefixes = new LinkedHashSet<>();
    Enumeration<NetworkInterface> interfaces = NetworkInterface.getNetworkInterfaces();
    while (interfaces != null && interfaces.hasMoreElements()) {
      NetworkInterface network = interfaces.nextElement();
      if (!network.isUp() || network.isLoopback() || network.isVirtual()) continue;
      Enumeration<InetAddress> addresses = network.getInetAddresses();
      while (addresses.hasMoreElements()) {
        InetAddress address = addresses.nextElement();
        if (!(address instanceof Inet4Address) || !address.isSiteLocalAddress()) continue;
        String[] octets = address.getHostAddress().split("\\.");
        if (octets.length == 4) {
          prefixes.add(octets[0] + "." + octets[1] + "." + octets[2] + ".");
        }
      }
    }
    return prefixes;
  }

  private List<String> discoveryBasePaths() {
    Set<String> paths = new LinkedHashSet<>();
    String entered = normaliseUrl(
      serverInput == null ? "" : serverInput.getText().toString()
    );
    if (entered != null) {
      int hostStart = entered.indexOf("://") + 3;
      int pathStart = entered.indexOf('/', hostStart);
      if (pathStart >= 0) paths.add(entered.substring(pathStart));
    }
    paths.add("/biometric/");
    paths.add("/");
    return new ArrayList<>(paths);
  }

  private String probeSchoolServer(String host, List<String> basePaths) {
    for (String basePath : basePaths) {
      Request request = new Request.Builder()
        .url("http://" + host + basePath + "api/mobile-discovery.php")
        .get()
        .build();
      try (Response response = discoveryHttp.newCall(request).execute()) {
        if (!response.isSuccessful() || response.body() == null) continue;
        JSONObject result = new JSONObject(response.body().string());
        if (
          result.optBoolean("ok") &&
          "mnhs-attendance-scanner".equals(result.optString("application"))
        ) {
          return normaliseUrl(result.optString("server_url", ""));
        }
      } catch (Exception ignored) {
        // Keep probing the remaining local devices and possible project paths.
      }
    }
    return null;
  }

  private void setServerDetectionStatus(String message, int color) {
    if (serverDetectionStatus == null) return;
    serverDetectionStatus.setText(message);
    serverDetectionStatus.setTextColor(color);
    serverDetectionStatus.setVisibility(message.isEmpty() ? View.GONE : View.VISIBLE);
  }

  private void signIn() {
    String server = normaliseUrl(serverInput.getText().toString());
    if (server == null) {
      showLoginError("Enter the school IP address.");
      return;
    }
    String configuredScannerName = automaticScannerName();
    String configuredScannerId = automaticScannerId();
    loginButton.setEnabled(false);
    loginButton.setText("Starting...");
    showLoginError("");
    networkExecutor.execute(() -> {
      RequestBody loginBody = new FormBody.Builder()
        .add("scanner_name", configuredScannerName)
        .add("scanner_id", configuredScannerId)
        .build();
      Request request = new Request.Builder()
        .url(server + "api/mobile-login.php")
        .post(loginBody)
        .build();
      try (Response response = http.newCall(request).execute()) {
        String payload =
          response.body() == null ? "" : response.body().string();
        JSONObject json = new JSONObject(payload);
        if (
          !response.isSuccessful() || !json.optBoolean("ok")
        ) throw new IOException(
          json.optString("message", "Could not sign in.")
        );
        baseUrl = server;
        scannerName = json.optString("scanner_name", "Mobile scanner");
        scannerId = json.optString("scanner_id", "");
        preferences
          .edit()
          .putString("serverUrl", server)
          .putString("scannerName", scannerName)
          .putString("scannerId", scannerId)
          .apply();
        runOnUiThread(this::showScanner);
      } catch (Exception error) {
        runOnUiThread(() -> {
          loginButton.setEnabled(true);
          loginButton.setText("Start scanner");
          showLoginError(readableError(error));
        });
      }
    });
  }

  private String automaticScannerName() {
    String manufacturer = (Build.MANUFACTURER == null ? "" : Build.MANUFACTURER).trim();
    String model = (Build.MODEL == null ? "Android phone" : Build.MODEL).trim();
    String name = (manufacturer + " " + model).trim();
    if (name.isEmpty()) name = "Android phone";
    return name.length() > 80 ? name.substring(0, 80) : name;
  }

  private String automaticScannerId() {
    String androidId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
    if (androidId == null || androidId.trim().isEmpty()) {
      androidId = preferences.getString("generatedScannerId", "");
      if (androidId.isEmpty()) {
        androidId = Long.toHexString(System.currentTimeMillis()) + Long.toHexString(System.nanoTime());
        preferences.edit().putString("generatedScannerId", androidId).apply();
      }
    }
    return "ANDROID-" + androidId.trim().toUpperCase();
  }

  private void showScanner() {
    stopScanner();
    bottomControls = null;
    scannerStatus = null;
    scanToastCard = null;
    scanResultCard = null;

    FrameLayout shell = new FrameLayout(this);
    shell.setBackground(
      new GradientDrawable(GradientDrawable.Orientation.TL_BR, new int[] {
        Color.rgb(30, 58, 95),
        Color.rgb(15, 23, 42),
        Color.rgb(2, 6, 23),
      })
    );

    ImageView watermark = new ImageView(this);
    watermark.setImageResource(R.drawable.maruhat_logo);
    watermark.setAlpha(.10f);
    watermark.setScaleType(ImageView.ScaleType.CENTER_INSIDE);
    shell.addView(
      watermark,
      new FrameLayout.LayoutParams(dp(340), dp(340), Gravity.CENTER)
    );

    LinearLayout card = column(10);
    card.setPadding(dp(26), dp(28), dp(26), dp(26));
    card.setBackground(round(Color.argb(235, 15, 23, 42), 22));
    TextView tag = text("MNHS ATTENDANCE", 12, Color.rgb(191, 219, 254));
    tag.setTypeface(null, 1);
    tag.setLetterSpacing(.12f);
    card.addView(tag);
    TextView copy = text(
      "The camera is off and ready. Start attendance when students are ready to scan.",
      15,
      Color.rgb(203, 213, 225)
    );
    copy.setPadding(0, dp(8), 0, dp(12));
    card.addView(copy);

    startButton = button("Start attendance", Color.rgb(37, 99, 235));
    card.addView(startButton, new LinearLayout.LayoutParams(-1, dp(52)));
    Button backButton = button("Go back", Color.rgb(71, 85, 105));
    LinearLayout.LayoutParams backParams = new LinearLayout.LayoutParams(
      -1,
      dp(52)
    );
    backParams.topMargin = dp(4);
    card.addView(backButton, backParams);
    updateButton = button("Update available", Color.rgb(5, 150, 105));
    updateButton.setVisibility(View.GONE);
    LinearLayout.LayoutParams updateParams = new LinearLayout.LayoutParams(
      -1,
      dp(52)
    );
    updateParams.topMargin = dp(4);
    card.addView(updateButton, updateParams);

    startButton.setOnClickListener(v -> showActiveScanner());
    backButton.setOnClickListener(v -> showLogin());
    updateButton.setOnClickListener(v -> downloadUpdate());
    FrameLayout.LayoutParams cardParams = new FrameLayout.LayoutParams(
      -1,
      -2,
      Gravity.CENTER
    );
    cardParams.setMargins(dp(24), dp(24), dp(24), dp(24));
    shell.addView(card, cardParams);
    setContentView(shell);
    checkForUpdate();
    startSessionKeepAlive();
  }

  private void showActiveScanner() {
    FrameLayout screen = new FrameLayout(this);
    screen.setBackgroundColor(Color.BLACK);
    previewView = new PreviewView(this);
    previewView.setScaleType(PreviewView.ScaleType.FILL_CENTER);
    screen.addView(previewView, new FrameLayout.LayoutParams(-1, -1));
    faceGuide = new FaceGuideView(this);
    screen.addView(faceGuide, new FrameLayout.LayoutParams(-1, -1));
    LinearLayout top = column(4);
    top.setPadding(dp(18), dp(48), dp(18), dp(14));
    top.setBackgroundColor(Color.argb(185, 2, 6, 23));
    TextView tag = text(
      scannerName.toUpperCase() +
        (scannerId.isEmpty() ? "" : " · " + scannerId),
      12,
      Color.rgb(191, 219, 254)
    );
    tag.setLetterSpacing(.12f);
    top.addView(tag);
    scannerStatus = text(
      "Ready. Tap Start scanner, then align one face inside the frame.",
      16,
      Color.WHITE
    );
    top.addView(scannerStatus);
    updateButton = button("Update available", Color.rgb(5, 150, 105));
    updateButton.setTextSize(13);
    updateButton.setVisibility(View.GONE);
    LinearLayout.LayoutParams updateParams = new LinearLayout.LayoutParams(
      -2,
      dp(42)
    );
    updateParams.topMargin = dp(8);
    top.addView(updateButton, updateParams);
    updateButton.setOnClickListener(v -> downloadUpdate());
    top.setVisibility(View.GONE);
    screen.addView(top, topParams());
    scanToastCard = new LinearLayout(this);
    scanToastCard.setGravity(Gravity.CENTER_VERTICAL);
    scanToastCard.setPadding(dp(18), dp(13), dp(14), dp(13));
    scanToastCard.setVisibility(View.GONE);
    scannerStatus = text("", 16, Color.WHITE);
    scannerStatus.setTypeface(null, 1);
    LinearLayout.LayoutParams toastTextParams = new LinearLayout.LayoutParams(
      0,
      -2,
      1
    );
    scanToastCard.addView(scannerStatus, toastTextParams);
    scanToastIcon = text("×", 27, Color.WHITE);
    scanToastIcon.setTypeface(null, 1);
    scanToastIcon.setGravity(Gravity.CENTER);
    scanToastCard.addView(
      scanToastIcon,
      new LinearLayout.LayoutParams(dp(32), dp(32))
    );
    FrameLayout.LayoutParams toastParams = new FrameLayout.LayoutParams(
      -1,
      -2,
      Gravity.TOP
    );
    toastParams.setMargins(dp(22), dp(48), dp(22), 0);
    screen.addView(scanToastCard, toastParams);
    scanResultCard = new LinearLayout(this);
    scanResultCard.setOrientation(LinearLayout.HORIZONTAL);
    scanResultCard.setGravity(Gravity.CENTER_VERTICAL);
    scanResultCard.setPadding(dp(12), dp(12), dp(16), dp(12));
    scanResultCard.setBackground(round(Color.argb(228, 2, 6, 23), 18));
    scanResultCard.setVisibility(View.GONE);
    scanResultImage = new ImageView(this);
    scanResultImage.setImageResource(R.drawable.maruhat_logo);
    scanResultImage.setScaleType(ImageView.ScaleType.CENTER_CROP);
    scanResultImage.setBackground(round(Color.rgb(30, 41, 59), 12));
    LinearLayout.LayoutParams resultImageParams = new LinearLayout.LayoutParams(
      dp(62),
      dp(62)
    );
    scanResultCard.addView(scanResultImage, resultImageParams);
    LinearLayout resultText = column(2);
    LinearLayout.LayoutParams resultTextParams = new LinearLayout.LayoutParams(
      0,
      -2,
      1
    );
    resultTextParams.leftMargin = dp(12);
    scanResultCard.addView(resultText, resultTextParams);
    scanResultAction = text(
      "Attendance recorded",
      12,
      Color.rgb(134, 239, 172)
    );
    scanResultAction.setTypeface(null, 1);
    scanResultAction.setLetterSpacing(.04f);
    resultText.addView(scanResultAction);
    scanResultName = text("Student", 18, Color.WHITE);
    scanResultName.setTypeface(null, 1);
    resultText.addView(scanResultName);
    TextView resultIcon = text("✓", 29, Color.rgb(134, 239, 172));
    resultIcon.setTypeface(null, 1);
    resultIcon.setGravity(Gravity.CENTER);
    scanResultCard.addView(
      resultIcon,
      new LinearLayout.LayoutParams(dp(34), dp(34))
    );
    FrameLayout.LayoutParams resultParams = new FrameLayout.LayoutParams(
      -1,
      -2,
      Gravity.TOP
    );
    resultParams.setMargins(dp(22), dp(48), dp(22), 0);
    screen.addView(scanResultCard, resultParams);
    bottomControls = new LinearLayout(this);
    bottomControls.setGravity(Gravity.CENTER);
    bottomControls.setPadding(dp(18), dp(14), dp(18), dp(30));
    bottomControls.setBackgroundColor(Color.argb(220, 2, 6, 23));
    startButton = button("Stop attendance", Color.rgb(31, 79, 145));
    Button backButton = button("Go back", Color.rgb(71, 85, 105));
    updateButton = button("Update", Color.rgb(5, 150, 105));
    updateButton.setVisibility(View.GONE);
    LinearLayout.LayoutParams actionParams = new LinearLayout.LayoutParams(
      0,
      dp(52),
      1
    );
    actionParams.setMargins(dp(4), 0, dp(4), 0);
    bottomControls.addView(startButton, actionParams);
    bottomControls.addView(backButton, actionParams);
    bottomControls.addView(updateButton, actionParams);
    startButton.setOnClickListener(v -> stopAttendanceAndReturnHome());
    backButton.setOnClickListener(v -> showLogin());
    updateButton.setOnClickListener(v -> downloadUpdate());
    FrameLayout.LayoutParams bottom = new FrameLayout.LayoutParams(
      -1,
      -2,
      Gravity.BOTTOM
    );
    screen.addView(bottomControls, bottom);
    View.OnTouchListener revealControls = (view, event) -> {
      showControlsTemporarily();
      return false;
    };
    screen.setOnTouchListener(revealControls);
    previewView.setOnTouchListener(revealControls);
    faceGuide.setOnTouchListener(revealControls);
    setContentView(screen);
    showControlsTemporarily();
    requestCameraAndStart();
  }

  private void showControlsTemporarily() {
    if (bottomControls == null) return;
    interfaceHandler.removeCallbacks(hideControls);
    bottomControls.animate().cancel();
    bottomControls.setAlpha(1f);
    bottomControls.setVisibility(View.VISIBLE);
    interfaceHandler.postDelayed(hideControls, 3000);
  }

  private void checkForUpdate() {
    networkExecutor.execute(() -> {
      try (
        Response response = http
          .newCall(
            new Request.Builder()
              .url(baseUrl + "api/mobile-app-update.php")
              .build()
          )
          .execute()
      ) {
        String payload =
          response.body() == null ? "" : response.body().string();
        JSONObject json = new JSONObject(payload);
        int versionCode = json.optInt("version_code", 0);
        if (
          response.isSuccessful() &&
          json.optBoolean("ok") &&
          versionCode > BuildConfig.VERSION_CODE
        ) {
          updateUrl = baseUrl + json.optString("apk_path", "");
          updateVersion = json.optString("version_name", "");
          runOnUiThread(() -> {
            if (updateButton != null) {
              updateButton.setText("Update v" + updateVersion);
              updateButton.setVisibility(View.VISIBLE);
              showControlsTemporarily();
            }
          });
        }
      } catch (Exception ignored) {}
    });
  }

  private void startSessionKeepAlive() {
    if (sessionKeepAliveTask != null) sessionKeepAliveTask.cancel(false);
    sessionKeepAliveTask = scanScheduler.scheduleAtFixedRate(
      () -> {
        try (
          Response response = http
            .newCall(
              new Request.Builder()
                .url(baseUrl + "api/mobile-session.php")
                .build()
            )
            .execute()
        ) {
          if (response.code() == 401) runOnUiThread(this::showLogin);
        } catch (Exception ignored) {}
      },
      3,
      3,
      TimeUnit.MINUTES
    );
  }

  private void downloadUpdate() {
    if (updateUrl.isEmpty()) return;
    if (
      Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
      !getPackageManager().canRequestPackageInstalls()
    ) {
      Toast.makeText(
        this,
        "Allow installs from MNHS Attendance, then tap Update available again.",
        Toast.LENGTH_LONG
      ).show();
      startActivity(
        new Intent(
          Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
          Uri.parse("package:" + getPackageName())
        )
      );
      return;
    }
    DownloadManager.Request request = new DownloadManager.Request(
      Uri.parse(updateUrl)
    )
      .setTitle("MNHS Attendance update")
      .setDescription("Downloading v" + updateVersion)
      .setNotificationVisibility(
        DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED
      )
      .setMimeType("application/vnd.android.package-archive")
      .setDestinationInExternalFilesDir(
        this,
        Environment.DIRECTORY_DOWNLOADS,
        "mnhs-attendance-v" + updateVersion + ".apk"
      );
    updateDownloadId = (
      (DownloadManager) getSystemService(DOWNLOAD_SERVICE)
    ).enqueue(request);
    updateButton.setEnabled(false);
    updateButton.setText("Downloading update...");
  }

  private void requestCameraAndStart() {
    if (
      ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) !=
      PackageManager.PERMISSION_GRANTED
    ) {
      ActivityCompat.requestPermissions(
        this,
        new String[] { Manifest.permission.CAMERA },
        CAMERA_PERMISSION
      );
      return;
    }
    startCamera();
  }

  private void startCamera() {
    setGuideStatus("Opening camera...", Color.rgb(191, 219, 254));
    ListenableFuture<ProcessCameraProvider> future =
      ProcessCameraProvider.getInstance(this);
    future.addListener(() -> {
      try {
        cameraProvider = future.get();
        cameraProvider.unbindAll();
        Preview preview = new Preview.Builder().build();
        preview.setSurfaceProvider(previewView.getSurfaceProvider());
        // A 960×720 JPEG remains detailed enough for the enrolled portrait
        // matcher, while uploading and decoding much faster over school Wi-Fi.
        imageCapture = new ImageCapture.Builder()
          .setTargetResolution(new Size(960, 720))
          .setJpegQuality(88)
          .setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY)
          .build();
        imageAnalysis = new ImageAnalysis.Builder()
          .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
          .build();
        imageAnalysis.setAnalyzer(faceExecutor, this::detectFace);
        cameraProvider.bindToLifecycle(
          this,
          CameraSelector.DEFAULT_FRONT_CAMERA,
          preview,
          imageCapture,
          imageAnalysis
        );
        scanning = true;
        faceVisible.set(false);
        faceGuide.setDetected(false);
        setGuideStatus("Detecting face...", Color.rgb(191, 219, 254));
        startButton.setText("Stop attendance");
        if (scanTask != null) scanTask.cancel(false);
        // Keep the two-frame server confirmation, but provide those frames
        // sooner once a face is visible.
        scanTask = scanScheduler.scheduleAtFixedRate(
          () -> {
            if (
              scanning && faceVisible.get() && !captureInFlight.get()
            ) captureFrame();
          },
          150,
          500,
          TimeUnit.MILLISECONDS
        );
      } catch (Exception error) {
        runOnUiThread(() ->
          showScanStatus("Could not open the camera. Try again.")
        );
      }
    }, ContextCompat.getMainExecutor(this));
  }

  private void detectFace(@NonNull ImageProxy imageProxy) {
    android.media.Image source = imageProxy.getImage();
    if (source == null) {
      imageProxy.close();
      return;
    }
    InputImage image = InputImage.fromMediaImage(
      source,
      imageProxy.getImageInfo().getRotationDegrees()
    );
    faceDetector
      .process(image)
      .addOnSuccessListener(faces -> {
        boolean detected = !faces.isEmpty();
        faceVisible.set(detected);
        Rect faceBounds = detected ? faces.get(0).getBoundingBox() : null;
        runOnUiThread(() -> {
          if (faceGuide == null) return;
          if (!detected) {
            faceGuide.setDetected(false);
            if (!captureInFlight.get()) {
              setGuideStatus("Detecting face...", Color.rgb(191, 219, 254));
            }
            return;
          }
          try {
            OutputTransform previewTransform =
              previewView == null ? null : previewView.getOutputTransform();
            if (previewTransform == null) {
              faceGuide.setDetected(false);
              return;
            }
            ImageProxyTransformFactory factory =
              new ImageProxyTransformFactory();
            factory.setUsingCropRect(true);
            factory.setUsingRotationDegrees(true);
            RectF mappedBounds = new RectF(faceBounds);
            new CoordinateTransform(
              factory.getOutputTransform(imageProxy),
              previewTransform
            ).mapRect(mappedBounds);
            faceGuide.setMappedBounds(mappedBounds);
            if (!captureInFlight.get()) {
              setGuideStatus(
                "Face detected - hold still",
                Color.rgb(134, 239, 172)
              );
            }
          } catch (Exception ignored) {
            faceGuide.setDetected(false);
          }
        });
      })
      .addOnCompleteListener(task -> imageProxy.close());
  }

  private void captureFrame() {
    if (
      imageCapture == null || !captureInFlight.compareAndSet(false, true)
    ) return;
    if (!cameraCaptureBusy.compareAndSet(false, true)) {
      captureInFlight.set(false);
      return;
    }
    runOnUiThread(() ->
      setGuideStatus("Recognizing face...", Color.rgb(253, 230, 138))
    );
    try {
      File frame = File.createTempFile(
        "attendance-frame-",
        ".jpg",
        getCacheDir()
      );
      ImageCapture.OutputFileOptions options =
        new ImageCapture.OutputFileOptions.Builder(frame).build();
      imageCapture.takePicture(
        options,
        cameraExecutor,
        new ImageCapture.OnImageSavedCallback() {
          @Override
          public void onImageSaved(
            @NonNull ImageCapture.OutputFileResults output
          ) {
            cameraCaptureBusy.set(false);
            uploadFrame(frame);
          }

          @Override
          public void onError(@NonNull ImageCaptureException error) {
            frame.delete();
            cameraCaptureBusy.set(false);
            captureInFlight.set(false);
          }
        }
      );
    } catch (IOException error) {
      cameraCaptureBusy.set(false);
      captureInFlight.set(false);
    }
  }

  private void uploadFrame(File frame) {
    if (!scanning) {
      frame.delete();
      captureInFlight.set(false);
      return;
    }
    RequestBody image = RequestBody.create(
      frame,
      MediaType.parse("image/jpeg")
    );
    RequestBody form = new MultipartBody.Builder()
      .setType(MultipartBody.FORM)
      .addFormDataPart("frame", "scanner-frame.jpg", image)
      .addFormDataPart("scan_type", "auto")
      .build();
    Request request = new Request.Builder()
      .url(baseUrl + "api/mobile-scan.php")
      .post(form)
      .build();
    try (Response response = scanHttp.newCall(request).execute()) {
      String payload = response.body() == null ? "" : response.body().string();
      JSONObject json = new JSONObject(payload);
      if (response.code() == 401) {
        if (scanning) runOnUiThread(() -> {
          if (!scanning) return;
          Toast.makeText(
            this,
            "Session expired. Sign in again.",
            Toast.LENGTH_LONG
          ).show();
          showLogin();
        });
        return;
      }
      if (!response.isSuccessful()) {
        String message = json.optString(
          "message",
          "Recognition service could not process this frame."
        );
        if (scanning) runOnUiThread(() -> {
          if (scanning) showScanStatus(message);
        });
        return;
      }
      JSONArray matches = json.optJSONArray("matches");
      if (matches != null && matches.length() > 0) {
        unrecognizedStreak = 0;
        unrecognizedMessageUntil = 0;
        recognitionGraceUntil = System.currentTimeMillis() + 5000;
        JSONObject match = matches.getJSONObject(0);
        if (scanning) runOnUiThread(() -> {
          if (scanning) showAttendanceResult(match);
        });
      } else {
        String message = json.optString("message", "Face not recognized.");
        if (
          scanning &&
          message.toLowerCase().contains("not recognized") &&
          ++unrecognizedStreak >= 5 &&
          System.currentTimeMillis() >= recognitionGraceUntil &&
          System.currentTimeMillis() >= unrecognizedMessageUntil
        ) runOnUiThread(() -> {
          if (scanning) {
            // Do not keep replaying the red warning while the same unknown
            // face remains in the camera preview.
            unrecognizedMessageUntil = System.currentTimeMillis() + 6000;
            unrecognizedStreak = 0;
            showFaceToast("Face not recognized.", false);
          }
        });
      }
    } catch (Exception error) {
      if (scanning) runOnUiThread(() -> {
        if (scanning) showScanStatus(
          "Scanner connection error: " + readableError(error)
        );
      });
    } finally {
      frame.delete();
      captureInFlight.set(false);
      if (scanning) runOnUiThread(() -> {
        if (!scanning) return;
        setGuideStatus(
          faceVisible.get() ? "Face detected - hold still" : "Detecting face...",
          faceVisible.get() ? Color.rgb(134, 239, 172) : Color.rgb(191, 219, 254)
        );
      });
    }
  }

  private void showScanStatus(String message) {
    showFaceToast(message, false);
  }

  private void setGuideStatus(String message, int color) {
    if (message.equals(guideStatusText) && faceGuide != null) return;
    guideStatusText = message;
    if (faceGuide != null) faceGuide.setStatus(message, color);
  }

  private void clearFaceToast() {
    if (scanToastCard == null) return;
    interfaceHandler.removeCallbacks(hideScanToast);
    scanToastCard.animate().cancel();
    scanToastCard.setVisibility(View.GONE);
  }

  private void showFaceToast(String message, boolean success) {
    if (scannerStatus == null || scanToastCard == null) return;
    interfaceHandler.removeCallbacks(hideScanToast);
    scanToastCard.animate().cancel();
    scannerStatus.setText(message);
    scanToastIcon.setText(success ? "✓" : "×");
    scanToastCard.setBackground(
      round(success ? Color.rgb(22, 163, 74) : Color.rgb(220, 38, 38), 14)
    );
    scanToastCard.setAlpha(1f);
    scanToastCard.setVisibility(View.VISIBLE);
    interfaceHandler.postDelayed(hideScanToast, 3000);
  }

  private void showAttendanceResult(JSONObject match) {
    // A prior frame may have shown an error while this next frame was
    // still being recognized. A real match always wins visually.
    clearFaceToast();
    if (scanResultCard == null) return;
    JSONObject student = match.optJSONObject("student");
    String name =
      student == null
        ? "Student"
        : student.optString("STUDENT_NAME", "Student");
    String action = match.optString("action", "");
    boolean logged = match.optBoolean("logged", false);
    String message = match.optString("message", "Attendance recorded");
    String label;
    if (!logged) {
      label = "complete".equals(action) ? "Attendance completed" : message;
    } else switch (action) {
      case "lunch_out":
        label = "Lunch out recorded";
        break;
      case "afternoon_time_in":
        label = "Afternoon time in recorded";
        break;
      case "time_out":
        label = "Time out recorded";
        break;
      default:
        label = "Time in recorded";
        break;
    }
    interfaceHandler.removeCallbacks(hideScanResult);
    scanResultCard.animate().cancel();
    scanResultAction.setText(label);
    scanResultName.setText(name);
    setGuideStatus(label, Color.rgb(134, 239, 172));
    scanResultImage.setImageResource(R.drawable.maruhat_logo);
    String path = student == null ? "" : student.optString("IMAGE_PATH", "");
    loadResultPhoto(path);
    scanResultCard.setAlpha(1f);
    scanResultCard.setVisibility(View.VISIBLE);
    String recognitionKey =
      (student == null ? name : student.optString("STUDENT_ID", name)) +
      "|" +
      action;
    long now = System.currentTimeMillis();
    boolean completed = "complete".equals(action);
    if (logged) {
      // A saved attendance step happens only once, so it should always
      // provide immediate audible confirmation.
      lastRecognitionKey = recognitionKey;
      matchMessageUntil = now + 4000;
      playRecognitionSound();
    } else if (
      completed &&
      (!recognitionKey.equals(lastRecognitionKey) || now >= matchMessageUntil)
    ) {
      // A completed record can be rediscovered by the live camera; keep
      // this reminder audible without replaying it every camera frame.
      lastRecognitionKey = recognitionKey;
      matchMessageUntil = now + 4000;
      playRecognitionSound();
    }
    interfaceHandler.postDelayed(hideScanResult, 3500);
  }

  private void playRecognitionSound() {
    try {
      if (recognitionTone != null) recognitionTone.startTone(
        ToneGenerator.TONE_PROP_ACK,
        180
      );
    } catch (Exception ignored) {}
    try {
      Uri notificationSound = RingtoneManager.getDefaultUri(
        RingtoneManager.TYPE_NOTIFICATION
      );
      Ringtone ringtone = RingtoneManager.getRingtone(this, notificationSound);
      if (ringtone != null) ringtone.play();
    } catch (Exception ignored) {}
  }

  private void loadResultPhoto(String imagePath) {
    if (
      scanResultImage == null || imagePath == null || imagePath.trim().isEmpty()
    ) return;
    String relativePath = imagePath.trim();
    String photoUrl = relativePath.matches("(?i)^https?://.*")
      ? relativePath
      : baseUrl + relativePath.replaceFirst("^/+", "");
    scanResultImage.setTag(photoUrl);
    imageExecutor.execute(() -> {
      try (
        Response response = http
          .newCall(new Request.Builder().url(photoUrl).build())
          .execute()
      ) {
        if (!response.isSuccessful() || response.body() == null) return;
        Bitmap photo = BitmapFactory.decodeStream(response.body().byteStream());
        if (photo != null) runOnUiThread(() -> {
          if (
            scanResultImage != null && photoUrl.equals(scanResultImage.getTag())
          ) scanResultImage.setImageBitmap(photo);
        });
      } catch (Exception ignored) {}
    });
  }

  private void stopScanner() {
    scanning = false;
    faceVisible.set(false);
    captureInFlight.set(false);
    cameraCaptureBusy.set(false);
    unrecognizedStreak = 0;
    recognitionGraceUntil = 0;
    unrecognizedMessageUntil = 0;
    if (scanTask != null) {
      scanTask.cancel(false);
      scanTask = null;
    }
    if (sessionKeepAliveTask != null) {
      sessionKeepAliveTask.cancel(false);
      sessionKeepAliveTask = null;
    }
    if (cameraProvider != null) cameraProvider.unbindAll();
    if (faceGuide != null) faceGuide.setDetected(false);
    setGuideStatus("Scanner stopped", Color.rgb(191, 219, 254));
    if (startButton != null) startButton.setText("Start attendance");
  }

  private void stopAttendanceAndReturnHome() {
    if (scannerTransitioning) return;
    scannerTransitioning = true;
    stopScanner();
    previewView = null;
    faceGuide = null;
    interfaceHandler.removeCallbacks(hideControls);
    interfaceHandler.removeCallbacks(hideScanToast);
    interfaceHandler.removeCallbacks(hideScanResult);

    interfaceHandler.post(() -> {
      if (!isFinishing() && !isDestroyed()) showScanner();
      scannerTransitioning = false;
    });
  }

  private void signOut() {
    stopScanner();
    networkExecutor.execute(() -> {
      try {
        http
          .newCall(
            new Request.Builder()
              .url(baseUrl + "api/mobile-logout.php")
              .post(RequestBody.create(new byte[0]))
              .build()
          )
          .execute()
          .close();
      } catch (Exception ignored) {}
      cookieManager.getCookieStore().removeAll();
      runOnUiThread(this::showLogin);
    });
  }

  private void showLoginError(String message) {
    loginStatus.setText(message);
    loginStatus.setVisibility(message.isEmpty() ? View.GONE : View.VISIBLE);
  }

  private String normaliseUrl(String value) {
    value = value.trim();
    if (value.isEmpty()) return null;
    if (value.matches("(?i)^https://.*")) value =
      "http://" + value.substring(8);
    if (!value.matches("(?i)^http://.*")) value = "http://" + value;
    return value.endsWith("/") ? value : value + "/";
  }

  private String readableError(Exception error) {
    return error.getMessage() == null
      ? "Could not reach the school server. Check the Wi-Fi address."
      : error.getMessage();
  }

  private LinearLayout column(int spacing) {
    LinearLayout view = new LinearLayout(this);
    view.setOrientation(LinearLayout.VERTICAL);
    if (spacing > 0) view.setDividerPadding(spacing);
    return view;
  }

  private TextView text(String value, int size, int color) {
    TextView view = new TextView(this);
    view.setText(value);
    view.setTextSize(size);
    view.setTextColor(color);
    return view;
  }

  private TextView label(String value) {
    TextView view = text(value, 13, Color.rgb(51, 65, 85));
    view.setTypeface(null, 1);
    view.setPadding(0, dp(8), 0, dp(4));
    return view;
  }

  private EditText input(String hint) {
    EditText view = new EditText(this);
    view.setHint(hint);
    view.setTextSize(16);
    view.setSingleLine(true);
    view.setPadding(dp(14), 0, dp(14), 0);
    view.setBackground(round(Color.rgb(248, 250, 252), 12));
    view.setLayoutParams(new LinearLayout.LayoutParams(-1, dp(52)));
    return view;
  }

  private Button button(String value, int color) {
    Button view = new Button(this);
    view.setText(value);
    view.setAllCaps(false);
    view.setTextColor(Color.WHITE);
    view.setTextSize(16);
    view.setTypeface(null, 1);
    view.setBackground(round(color, 13));
    return view;
  }

  private GradientDrawable round(int color, int radius) {
    GradientDrawable background = new GradientDrawable();
    background.setColor(color);
    background.setCornerRadius(dp(radius));
    return background;
  }

  private FrameLayout.LayoutParams topParams() {
    return new FrameLayout.LayoutParams(-1, -2, Gravity.TOP);
  }

  private int dp(int value) {
    return Math.round(value * getResources().getDisplayMetrics().density);
  }

  private final class FaceGuideView extends View {

    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint labelPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint labelBackgroundPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final RectF bounds = new RectF();
    private boolean detected;
    private String statusText = "Detecting face...";
    private int statusColor = Color.rgb(191, 219, 254);

    FaceGuideView(MainActivity activity) {
      super(activity);
      paint.setStyle(Paint.Style.STROKE);
      paint.setStrokeCap(Paint.Cap.SQUARE);
      labelPaint.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
      labelBackgroundPaint.setColor(Color.argb(220, 2, 6, 23));
      setLayerType(View.LAYER_TYPE_SOFTWARE, null);
    }

    void setDetected(boolean value) {
      detected = value;
      invalidate();
    }

    void setMappedBounds(RectF value) {
      if (value == null || value.width() < dp(20) || value.height() < dp(20)) {
        setDetected(false);
        return;
      }
      bounds.set(value);
      bounds.inset(-bounds.width() * .08f, -bounds.height() * .10f);
      setDetected(true);
    }

    void setStatus(String message, int color) {
      statusText = message;
      statusColor = color;
      invalidate();
    }

    @Override
    protected void onDraw(Canvas canvas) {
      super.onDraw(canvas);
      if (!detected) return;
      float left = Math.max(0, bounds.left);
      float right = Math.min(getWidth(), bounds.right);
      float top = Math.max(0, bounds.top);
      float bottom = Math.min(getHeight(), bounds.bottom);
      float width = right - left;
      float height = bottom - top;
      if (width <= 0 || height <= 0) return;
      float corner = Math.max(dp(14), Math.min(width, height) * .28f);
      paint.setColor(Color.rgb(34, 197, 94));
      paint.setStrokeWidth(dp(5));
      paint.setShadowLayer(dp(5), 0, 0, Color.argb(220, 0, 0, 0));
      canvas.drawLine(left, top, left + corner, top, paint);
      canvas.drawLine(left, top, left, top + corner, paint);
      canvas.drawLine(right, top, right - corner, top, paint);
      canvas.drawLine(right, top, right, top + corner, paint);
      canvas.drawLine(left, bottom, left + corner, bottom, paint);
      canvas.drawLine(left, bottom, left, bottom - corner, paint);
      canvas.drawLine(right, bottom, right - corner, bottom, paint);
      canvas.drawLine(right, bottom, right, bottom - corner, paint);
      paint.clearShadowLayer();

      labelPaint.setColor(statusColor);
      labelPaint.setTextSize(dp(12));
      float labelPadding = dp(9);
      float labelHeight = dp(31);
      float labelWidth = labelPaint.measureText(statusText) + labelPadding * 2;
      float labelLeft = Math.max(
        dp(8),
        Math.min(right - labelWidth, getWidth() - labelWidth - dp(8))
      );
      float labelTop = bottom + dp(9);
      if (labelTop + labelHeight > getHeight() - dp(8)) {
        labelTop = Math.max(dp(8), bottom - labelHeight - dp(9));
      }
      RectF labelBounds = new RectF(
        labelLeft,
        labelTop,
        labelLeft + labelWidth,
        labelTop + labelHeight
      );
      canvas.drawRoundRect(labelBounds, dp(12), dp(12), labelBackgroundPaint);
      Paint.FontMetrics fontMetrics = labelPaint.getFontMetrics();
      float textBaseline = labelTop +
        (labelHeight - fontMetrics.bottom + fontMetrics.top) / 2f -
        fontMetrics.top;
      canvas.drawText(statusText, labelLeft + labelPadding, textBaseline, labelPaint);
    }
  }

  @Override
  protected void onStop() {
    if (scanning) {
      returnToScannerHomeOnResume = true;
      stopScanner();
    }
    super.onStop();
  }

  @Override
  protected void onResume() {
    super.onResume();
    if (returnToScannerHomeOnResume) {
      returnToScannerHomeOnResume = false;
      showScanner();
    }
  }

  @Override
  public void onRequestPermissionsResult(
    int requestCode,
    @NonNull String[] permissions,
    @NonNull int[] results
  ) {
    super.onRequestPermissionsResult(requestCode, permissions, results);
    if (
      requestCode == CAMERA_PERMISSION &&
      results.length > 0 &&
      results[0] == PackageManager.PERMISSION_GRANTED
    ) startCamera();
    else {
      Toast.makeText(
        this,
        "Camera permission is required to scan attendance.",
        Toast.LENGTH_LONG
      ).show();
      showScanner();
    }
  }

  @Override
  protected void onDestroy() {
    stopScanner();
    unregisterReceiver(updateReceiver);
    if (recognitionTone != null) recognitionTone.release();
    faceDetector.close();
    networkExecutor.shutdownNow();
    imageExecutor.shutdownNow();
    cameraExecutor.shutdownNow();
    faceExecutor.shutdownNow();
    scanScheduler.shutdownNow();
    super.onDestroy();
  }
}
