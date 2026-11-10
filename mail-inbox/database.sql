-- ============================================================
-- دیتابیس فروشگاه جامع موبایل 72412  |  مالک: رضا زهدی  |  تلفن: 09156481181
-- ابتدا دیتابیس mobile_db را در phpMyAdmin انتخاب کنید، سپس همین فایل را Import کنید.
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS coupon_products, coupons, stock_notifications, contact_messages, mail_messages, shipping_settings, product_specs, order_items, orders, products, categories, users;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(60) UNIQUE DEFAULT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    role ENUM('user','admin') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE,
    status TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(191) NULL UNIQUE,
    name VARCHAR(300) NOT NULL,
    name_en VARCHAR(300),
    slug VARCHAR(255) NULL,
    brand VARCHAR(100),
    category_id INT,
    category_path VARCHAR(500),
    price DECIMAL(15,0) NOT NULL,
    sale_price DECIMAL(15,0) DEFAULT NULL,
    discount_percent INT DEFAULT 0,
    stock INT DEFAULT 10,
    stock_status ENUM('in_stock','out_of_stock') DEFAULT 'in_stock',
    main_image VARCHAR(500),
    all_images TEXT,
    description TEXT,
    short_desc VARCHAR(500),
    warranty VARCHAR(200),
    seller VARCHAR(200),
    source_url VARCHAR(500),
    rating DECIMAL(4,2) DEFAULT 0,
    review_count INT DEFAULT 0,
    sold INT DEFAULT 0,
    views INT DEFAULT 0,
    status TINYINT DEFAULT 1,
    featured TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_cat (category_id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE product_specs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    group_name VARCHAR(150),
    spec_key VARCHAR(200),
    spec_value TEXT,
    sort_order INT DEFAULT 0,
    KEY idx_product (product_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(40) NOT NULL UNIQUE,
    user_id INT NULL,
    customer_name VARCHAR(150),
    phone VARCHAR(20),
    email VARCHAR(150),
    province VARCHAR(80),
    city VARCHAR(80),
    address TEXT,
    postal_code VARCHAR(20),
    notes TEXT,
    subtotal DECIMAL(15,0) DEFAULT 0,
    discount DECIMAL(15,0) DEFAULT 0,
    shipping DECIMAL(15,0) DEFAULT 0,
    total DECIMAL(15,0) DEFAULT 0,
    payment_method VARCHAR(50),
    coupon_code VARCHAR(50) NULL,
    coupon_discount DECIMAL(15,0) NOT NULL DEFAULT 0,
    payment_status VARCHAR(30) DEFAULT 'pending',
    status ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(300),
    product_image VARCHAR(500),
    unit_price DECIMAL(15,0),
    quantity INT DEFAULT 1,
    subtotal DECIMAL(15,0),
    KEY idx_order (order_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE shipping_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value VARCHAR(100) NOT NULL,
    setting_label VARCHAR(200),
    setting_group VARCHAR(50),
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150),
    phone VARCHAR(30),
    message TEXT,
    product_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- صندوق پیام مدیر: ایمیل‌های دریافتی/ارسالی و پیام‌های فرم تماس (ماژول includes/mail.php)
CREATE TABLE mail_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('in','out') NOT NULL DEFAULT 'in',
    source VARCHAR(20) NOT NULL DEFAULT 'email',
    thread_id INT NULL,
    from_name VARCHAR(190) NULL,
    from_email VARCHAR(190) NULL,
    to_email VARCHAR(190) NULL,
    phone VARCHAR(30) NULL,
    product_id INT NULL,
    subject VARCHAR(255) NULL,
    body MEDIUMTEXT NULL,
    attachments INT NOT NULL DEFAULT 0,
    message_id VARCHAR(190) NULL,
    in_reply_to VARCHAR(190) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_trashed TINYINT(1) NOT NULL DEFAULT 0,
    send_status ENUM('none','sent','failed') NOT NULL DEFAULT 'none',
    send_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_message_id (message_id),
    KEY idx_list (direction, is_trashed, id),
    KEY idx_thread (thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE stock_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    phone VARCHAR(30) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(200) DEFAULT NULL,
    type ENUM('percent','amount') NOT NULL DEFAULT 'percent',
    value DECIMAL(15,0) NOT NULL DEFAULT 0,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    max_uses INT NULL DEFAULT NULL,
    used_count INT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE coupon_products (
    coupon_id INT NOT NULL,
    product_id INT NOT NULL,
    PRIMARY KEY (coupon_id, product_id),
    KEY idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- مدیر سایت (ورود پنل: ایمیل reza@local یا نام کاربری reza  |  رمز: 1328 — بعد از ورود تغییر دهید)
INSERT INTO users (name, username, email, password, phone, role) VALUES
('رضا زهدی', 'reza', 'reza@local', '$2y$10$SU1dHPxmuBAyejdtchVyieESFCCBBsvuf4VECJg6ezXRIxaJwJYda', '09156481181', 'admin');

INSERT INTO categories (name, slug) VALUES
('گوشی موبایل', 'mobile'),
('لوازم جانبی', 'accessories'),
('ساعت هوشمند', 'watch'),
('تبلت', 'tablet');

INSERT INTO products (name, brand, category_id, price, sale_price, discount_percent, main_image, description) VALUES
('گوشی موبایل اپل مدل iPhone 15 Pro Max ظرفیت 256 گیگابایت', 'اپل', 1, 68500000, 65000000, 5, 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?w=500', 'آیفون ۱۵ پرو مکس با تراشه A17 Pro و بدنه تیتانیومی'),
('گوشی سامسونگ مدل Galaxy S24 Ultra ظرفیت 256 گیگابایت', 'سامسونگ', 1, 62000000, 59000000, 5, 'https://images.unsplash.com/photo-1610945265064-0e34e5519bbf?w=500', 'سامسونگ S24 اولترا با قلم S Pen و هوش مصنوعی Galaxy AI'),
('هدفون بی‌سیم اپل مدل AirPods Pro 2', 'اپل', 2, 11200000, NULL, 0, 'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?w=500', 'ایرپاد پرو ۲ با حذف نویز فعال'),
('گوشی شیائومی مدل Redmi Note 13 Pro ظرفیت 256 گیگابایت', 'شیائومی', 1, 18900000, 16065000, 15, 'https://images.unsplash.com/photo-1598327105666-5b89351aff97?w=500', 'ردمی نوت ۱۳ پرو با دوربین ۲۰۰ مگاپیکسل');

INSERT INTO shipping_settings (setting_key, setting_value, setting_label, setting_group, sort_order) VALUES
('base_post_express','200000','پست پیشتاز','base_cost',1),
('base_post_normal','200000','پست عادی','base_cost',2),
('base_freight','200000','باربری','base_cost',3),
('base_cod','200000','پرداخت در محل','base_cost',4),
('free_shipping_threshold','0','ارسال رایگان برای خرید بالاتر از (تومان) — ۰ یعنی غیرفعال','free_shipping',5),
('factor_تهران','1.0','ضریب تهران','province_factor',10),
('factor_البرز','1.0','ضریب البرز','province_factor',11),
('factor_اصفهان','1.0','ضریب اصفهان','province_factor',12),
('factor_فارس','1.0','ضریب فارس','province_factor',13),
('factor_خراسان رضوی','1.0','ضریب خراسان رضوی','province_factor',14),
('factor_آذربایجان شرقی','1.0','ضریب آذربایجان شرقی','province_factor',15),
('factor_آذربایجان غربی','1.0','ضریب آذربایجان غربی','province_factor',16),
('factor_گیلان','1.0','ضریب گیلان','province_factor',17),
('factor_مازندران','1.0','ضریب مازندران','province_factor',18),
('factor_خوزستان','1.0','ضریب خوزستان','province_factor',19),
('factor_کرمان','1.0','ضریب کرمان','province_factor',20),
('factor_یزد','1.0','ضریب یزد','province_factor',21),
('factor_قم','1.0','ضریب قم','province_factor',22),
('factor_مرکزی','1.0','ضریب مرکزی','province_factor',23),
('factor_همدان','1.0','ضریب همدان','province_factor',24),
('factor_کرمانشاه','1.0','ضریب کرمانشاه','province_factor',25),
('factor_گلستان','1.0','ضریب گلستان','province_factor',26),
('factor_سیستان و بلوچستان','1.0','ضریب سیستان و بلوچستان','province_factor',27),
('factor_هرمزگان','1.0','ضریب هرمزگان','province_factor',28),
('factor_بوشهر','1.0','ضریب بوشهر','province_factor',29),
('factor_اردبیل','1.0','ضریب اردبیل','province_factor',30),
('factor_زنجان','1.0','ضریب زنجان','province_factor',31),
('factor_قزوین','1.0','ضریب قزوین','province_factor',32),
('factor_لرستان','1.0','ضریب لرستان','province_factor',33),
('factor_چهارمحال و بختیاری','1.0','ضریب چهارمحال و بختیاری','province_factor',34),
('factor_کهگیلویه و بویراحمد','1.0','ضریب کهگیلویه و بویراحمد','province_factor',35),
('factor_سمنان','1.0','ضریب سمنان','province_factor',36),
('factor_خراسان شمالی','1.0','ضریب خراسان شمالی','province_factor',37),
('factor_خراسان جنوبی','1.0','ضریب خراسان جنوبی','province_factor',38),
('factor_ایلام','1.0','ضریب ایلام','province_factor',39),
('factor_کردستان','1.0','ضریب کردستان','province_factor',40);
