# Distroless: the gateway is a static binary and needs no shell, no package
# manager and no libc. Nothing to exploit if something goes wrong inside it.
FROM gcr.io/distroless/static-debian12:nonroot

COPY librenms-webterm-gw /usr/bin/librenms-webterm-gw

USER nonroot:nonroot
EXPOSE 8377

# The binary probes itself: there is no curl in this image, by design.
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD ["/usr/bin/librenms-webterm-gw", "healthcheck"]

ENTRYPOINT ["/usr/bin/librenms-webterm-gw"]
CMD ["serve"]
