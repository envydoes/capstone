<?php
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// db_setup.php  â€” run once to create the table
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$host     = "o7jpqmin0zgconui4xtnfju6";
$dbuser   = "root";
$password = "UKkJ05DHQDMMMOxFEUI5f1HJGVj8Vb5gfJAEvAESTGCVWDtFEGb42qX67AxGUXvj";
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

    -- â”€â”€ Apartment-specific columns â”€â”€
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

    -- â”€â”€ Business-specific columns â”€â”€
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

    -- â”€â”€ Shared contact / address columns â”€â”€
    contact        VARCHAR(30)  DEFAULT NULL,
    email          VARCHAR(255) DEFAULT NULL,
    houseNum       VARCHAR(50)  DEFAULT NULL,
    street         VARCHAR(255) DEFAULT NULL,
    barangay       VARCHAR(255) DEFAULT NULL,
    city           VARCHAR(255) DEFAULT NULL,

    -- â”€â”€ Photos â”€â”€
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