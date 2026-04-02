# Protobuf Schema Notes

The canonical schema is stored in `proto/amida_product_delta_feed_v1.proto`.

The module implements its own tiny protobuf encoder so the hot feed path does not depend on a runtime code generator. The schema is still published as `.proto` so downstream consumers can generate strongly typed clients in Python, Go, Rust, Java, Node.js or PHP.
