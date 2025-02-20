# docker/wordpress/Dockerfile
FROM wordpress:latest

# Install SVN (Subversion)
RUN apt-get update && \
    apt-get install -y subversion && \
    rm -rf /var/lib/apt/lists/*

# Install Composer prerequisites, download and install Composer
RUN apt-get update && \
    apt-get install -y curl unzip && \
    rm -rf /var/lib/apt/lists/* && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
