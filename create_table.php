<?php
// ──────────────────────────────────────────────
// db_setup.php  — run once to create the table
// ──────────────────────────────────────────────
$host     = "localhost";
$dbuser   = "root";
$password = "";
$database = "sumeste_db";

$conn = mysqli_connect($host, $dbuser, $password, $database);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Drop old table so script is repeatable during development
mysqli_query($conn, "DROP TABLE IF EXISTS tbl_busaptListing");

$sql = "
CREATE TABLE tbl_busaptListing (
    id             INT          AUTO_INCREMENT PRIMARY KEY,
    userId         VARCHAR(255) NOT NULL,

    listingType    ENUM('apartment','business') NOT NULL,
    slotsAvailable INT          DEFAULT 0,

    -- ── Apartment-specific columns ──
    aptType        VARCHAR(50)  DEFAULT NULL,
    aptTitle       VARCHAR(255) DEFAULT NULL,
    aptStatus      ENUM('available','occupied','inquire') DEFAULT NULL,
    aptPrice       DECIMAL(10,2) DEFAULT NULL,
    aptFloor       VARCHAR(100) DEFAULT NULL,
    aptRooms       INT          DEFAULT NULL,
    aptOccupants   INT          DEFAULT NULL,
    aptBath        VARCHAR(50)  DEFAULT NULL,
    aptIncluded    TEXT         DEFAULT NULL,   -- JSON array
    aptAmenities   TEXT         DEFAULT NULL,   -- JSON array
    aptRules       TEXT         DEFAULT NULL,   -- JSON array
    aptDesc        TEXT         DEFAULT NULL,
    aptAddress     TEXT         DEFAULT NULL,
    aptMapsLink    TEXT         DEFAULT NULL,

    -- ── Business-specific columns ──
    bussCat        VARCHAR(100) DEFAULT NULL,
    bussName       VARCHAR(255) DEFAULT NULL,
    bussStatus     ENUM('open','new','temp-closed','for-rent') DEFAULT NULL,
    bussPrice      VARCHAR(100) DEFAULT NULL,
    bussYears      VARCHAR(50)  DEFAULT NULL,
    bussOpen       TIME         DEFAULT NULL,
    bussClose      TIME         DEFAULT NULL,
    bussDays       TEXT         DEFAULT NULL,   -- JSON array
    bussFeatures   TEXT         DEFAULT NULL,   -- JSON array
    bussDesc       TEXT         DEFAULT NULL,
    bussAddress    TEXT         DEFAULT NULL,
    bussMapsLink   TEXT         DEFAULT NULL,

    -- ── Shared contact / address columns ──
    contact        VARCHAR(30)  DEFAULT NULL,
    email          VARCHAR(255) DEFAULT NULL,
    houseNum       VARCHAR(50)  DEFAULT NULL,
    street         VARCHAR(255) DEFAULT NULL,
    barangay       VARCHAR(255) DEFAULT NULL,
    city           VARCHAR(255) DEFAULT NULL,

    -- ── Photos ──
    photos         TEXT         DEFAULT NULL,   -- JSON array of file paths

    createdAt      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (userId) REFERENCES tbl_userinfo(accID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

if (mysqli_query($conn, $sql)) {
    echo "Table <strong>tbl_busaptListing</strong> created successfully.";
} else {
    echo "Error creating table: " . mysqli_error($conn);
}

mysqli_close($conn);
echo "<br>Database setup complete.";